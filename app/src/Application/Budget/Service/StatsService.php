<?php

declare(strict_types=1);

namespace App\Application\Budget\Service;

use App\Component\ExchangeRate\ExchangeRateService;
use App\Component\LLM\Client\Contract\LLMClientInterface;
use App\Component\LLM\DTO\ChatRequest;
use App\Component\LLM\DTO\Message;
use App\Component\LLM\DTO\ToolDefinition;
use App\Component\LLM\Exception\TokenLimitExceededException;
use App\Component\LLM\LLMClientFactory;
use App\Component\LLM\Repository\LlmUsageRepository;
use App\Component\Telegram\Repository\ChatRepository;
use App\Component\Telegram\Repository\MessageRepository;
use App\Service\Config\Config;
use App\Service\Settings\Repository\ChatPromptRepository;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;

#[Injectable]
class StatsService
{
    private LLMClientInterface $llmClient;

    public function __construct(
        private MessageRepository $messageRepo,
        private ChatPromptRepository $promptRepo,
        private ChatRepository $chatRepo,
        private LLMClientFactory $llmFactory,
        private ExchangeRateService $exchangeRateService,
        private LlmUsageRepository $usageRepo,
        private Config $config,
        private LoggerInterface $logger
    ) {
        $provider = $this->config->get('llm.default_provider', 'claude');
        $this->logger->info('[Stats] Initializing LLM client', ['provider' => $provider]);
        $this->llmClient = $this->llmFactory->createByCode($provider);
        $this->logger->info('[Stats] LLM client created', [
            'provider_code' => $this->llmClient->getProviderCode(),
            'model' => $this->llmClient->getDefaultModel(),
        ]);
    }

    private const array CATEGORY_EMOJI = [
        'аренда' => '🏠',
        'еда' => '🍔',
        'питомцы' => '🐾',
        'транспорт' => '🚗',
        'развлечения' => '🎮',
        'здоровье' => '💊',
        'бытовые' => '🧹',
        'одежда' => '👕',
        'подписки' => '📱',
        'связь' => '📞',
        'спорт' => '⚽',
        'отпуск' => '✈️',
        'красота' => '💅',
        'другое' => '📦',
        'зарплата' => '💵',
        'переводы' => '💸',
        'продажи' => '🏷️',
        'возвраты' => '↩️',
        'баланс' => '💰',
    ];

    public function getStats(int $chatId, int $months, string $currency = 'THB', bool $verbose = false, ?int $topicId = null): array
    {
        $this->logger->info('[Stats] START getStats', [
            'chat_id' => $chatId,
            'months' => $months,
            'currency' => $currency,
            'verbose' => $verbose,
            'topic_id' => $topicId,
        ]);

        $billingDay = $this->chatRepo->getBillingDay($chatId);
        $messages = $this->messageRepo->getForChat($chatId, $months, $billingDay, $topicId);

        $this->logger->info('[Stats] Messages fetched', [
            'chat_id' => $chatId,
            'billing_day' => $billingDay,
            'total_messages' => count($messages),
        ]);

        if (empty($messages)) {
            $this->logger->info('[Stats] No messages found, returning empty');
            return [
                'text' => "Нет записей за последние {$months} мес.",
                'tokens' => 0,
            ];
        }

        $cached = [];
        $uncategorized = [];
        foreach ($messages as $msg) {
            if (!empty($msg['categorized'])) {
                $items = is_string($msg['categorized']) ? json_decode($msg['categorized'], true) : $msg['categorized'];
                foreach ($items as $item) {
                    $cached[] = $item;
                }
            } else {
                $uncategorized[] = $msg;
            }
        }

        $this->logger->info('[Stats] Messages split', [
            'cached_items' => count($cached),
            'uncategorized_messages' => count($uncategorized),
        ]);

        $tokensUsed = 0;

        if (!empty($uncategorized)) {
            $this->checkTokenLimit();
            $this->logger->info('[Stats] Token limit check passed');

            $categories = $this->chatRepo->getCategories($chatId) ?? $this->config->get('llm.default_categories');
            $systemPrompt = $this->buildSystemPrompt($chatId, $currency, $categories, $topicId);
            $context = $this->formatMessagesAsJson($uncategorized);
            $userContent = json_encode(['messages' => $context], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            $this->logger->info('[Stats] LLM request prepared', [
                'provider' => $this->llmClient->getProviderCode(),
                'system_prompt_length' => strlen($systemPrompt),
                'user_content_length' => strlen($userContent),
                'messages_to_categorize' => count($context),
            ]);

            try {
                $tool = $this->buildCategorizationTool();
                $request = ChatRequest::create([
                    Message::system($systemPrompt),
                    Message::user($userContent),
                ]);

                $maxTokens = (int) $this->config->get('llm.providers.claude.max_tokens', 64000);
                $request
                    ->withTools([$tool])
                    ->withMaxTokens($maxTokens);

                $this->logger->info('[Stats] Sending LLM request', [
                    'max_tokens' => $maxTokens,
                    'has_tools' => true,
                ]);

                $response = $this->llmClient->chat($request);

                $this->logger->info('[Stats] LLM response received', [
                    'finish_reason' => $response->finishReason,
                    'has_tool_calls' => !empty($response->toolCalls),
                    'tool_calls_count' => count($response->toolCalls),
                    'has_text_content' => $response->content !== null,
                    'text_content_preview' => $response->content !== null ? mb_substr($response->content, 0, 200) : null,
                    'input_tokens' => $response->usage->inputTokens,
                    'output_tokens' => $response->usage->outputTokens,
                    'model' => $response->model,
                ]);

                $this->usageRepo->logUsage(
                    $this->llmClient->getProviderCode(),
                    $response->usage->inputTokens,
                    $response->usage->outputTokens
                );

                $newItems = $this->extractToolData($response);

                $this->logger->info('[Stats] Tool data extracted', [
                    'items_count' => count($newItems),
                    'items_preview' => array_slice($newItems, 0, 3),
                ]);

                if (empty($newItems)) {
                    $this->logger->warning('[Stats] No items extracted from LLM response', [
                        'response_content' => $response->content,
                        'tool_calls_raw' => array_map(fn($tc) => [
                            'id' => $tc->id,
                            'name' => $tc->name,
                            'args_keys' => array_keys($tc->arguments),
                        ], $response->toolCalls),
                    ]);
                }

                $this->messageRepo->updateCategorization($newItems);

                $this->logger->info('[Stats] Categorization saved to DB', [
                    'items_saved' => count($newItems),
                ]);

                $cached = array_merge($cached, $newItems);
                $tokensUsed = $response->usage->totalTokens;
            } catch (\Throwable $e) {
                $this->logger->error('[Stats] LLM request FAILED', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'chat_id' => $chatId,
                    'trace' => $e->getTraceAsString(),
                ]);

                if (empty($cached)) {
                    return [
                        'text' => $this->formatBasicStats($messages, $months),
                        'tokens' => 0,
                    ];
                }
            }
        }

        $converted = $this->convertCurrencies($cached, $currency);
        $statsText = $this->formatStats($converted, $currency, $verbose);

        return [
            'text' => $statsText,
            'tokens' => $tokensUsed,
        ];
    }

    private function buildCategorizationTool(): ToolDefinition
    {
        return new ToolDefinition(
            'categorize_transactions',
            'Извлекает и категоризирует финансовые позиции из текста',
            [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'description' => 'Список финансовых позиций',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'message_id' => [
                                    'type' => 'integer',
                                    'description' => 'ID сообщения из входных данных',
                                ],
                                'type' => [
                                    'type' => 'string',
                                    'enum' => ['income', 'expense', 'balance'],
                                    'description' => 'Тип: income (доход), expense (расход), balance (остаток на счёте)',
                                ],
                                'wallet' => [
                                    'type' => 'string',
                                    'description' => 'Название кошелька/счёта (обязательно для balance)',
                                ],
                                'category' => ['type' => 'string', 'description' => 'Категория'],
                                'amount' => ['type' => 'number', 'description' => 'Сумма как в тексте'],
                                'currency' => ['type' => 'string', 'description' => 'Валюта позиции'],
                                'description' => ['type' => 'string', 'description' => 'Описание из текста'],
                            ],
                            'required' => ['message_id', 'type', 'category', 'amount', 'currency', 'description'],
                        ],
                    ],
                ],
                'required' => ['items'],
            ]
        );
    }

    private function extractToolData($response): array
    {
        if (!empty($response->toolCalls)) {
            $items = [];
            foreach ($response->toolCalls as $toolCall) {
                $this->logger->debug('[Stats:extractToolData] Processing tool_call', [
                    'tool_id' => $toolCall->id,
                    'tool_name' => $toolCall->name,
                    'arguments_keys' => array_keys($toolCall->arguments),
                    'items_count' => count($toolCall->arguments['items'] ?? []),
                ]);
                $callItems = $toolCall->arguments['items'] ?? [];
                array_push($items, ...$callItems);
            }
            $this->logger->info('[Stats:extractToolData] Extracted from tool_calls', ['total_items' => count($items)]);
            return $items;
        }

        $text = $response->asText();
        $this->logger->info('[Stats:extractToolData] No tool_calls, trying text fallback', [
            'has_text' => $text !== null,
            'text_length' => $text !== null ? strlen($text) : 0,
        ]);

        if ($text && preg_match('/```json\s*(.*?)\s*```/s', $text, $matches)) {
            $json = json_decode($matches[1], true);
            $items = $json['items'] ?? $json['transactions'] ?? [];
            $this->logger->info('[Stats:extractToolData] Extracted from JSON in text', ['items_count' => count($items)]);

            return $items;
        }

        $this->logger->warning('[Stats:extractToolData] No data extracted at all');
        return [];
    }

    private function convertCurrencies(array $items, string $targetCurrency): array
    {
        $result = [];

        foreach ($items as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            $currency = strtoupper($item['currency'] ?? $targetCurrency);

            if ($currency !== $targetCurrency) {
                $converted = $this->exchangeRateService->convert($amount, $currency, $targetCurrency);
                if ($converted !== null) {
                    $amount = $converted;
                }
            }

            $result[] = [
                'message_id' => $item['message_id'] ?? null,
                'type' => $item['type'] ?? 'expense',
                'wallet' => $item['wallet'] ?? null,
                'category' => $item['category'] ?? 'другое',
                'amount' => $amount,
                'description' => $item['description'] ?? '',
                'original_currency' => $currency,
                'original_amount' => (float) ($item['amount'] ?? 0),
            ];
        }

        return $result;
    }

    private function formatStats(array $items, string $currency, bool $verbose = false): string
    {
        $income = array_filter($items, fn($i) => $i['type'] === 'income');
        $expenses = array_filter($items, fn($i) => $i['type'] === 'expense');
        $balances = array_filter($items, fn($i) => $i['type'] === 'balance');

        $incomeByCategory = $this->groupByCategory($income);
        $expensesByCategory = $this->groupByCategory($expenses);

        $totalIncome = array_sum(array_column($income, 'amount'));
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        $totalWalletBalance = $this->calculateWalletBalances($balances);
        $periodBalance = $totalIncome - $totalExpenses;

        $lines = [];

        if (!empty($incomeByCategory)) {
            $lines[] = '<b>📈 Доходы</b>';
            foreach ($incomeByCategory as $category => $categoryItems) {
                $emoji = $this->getCategoryEmoji($category);
                $sum = array_sum(array_column($categoryItems, 'amount'));
                $lines[] = "• {$emoji} {$category}: " . $this->formatAmount($sum) . " {$currency}";
                foreach ($categoryItems as $item) {
                    $lines[] = $this->formatItemLine($item, $currency);
                }
            }
            $lines[] = '<b>Итого:</b> ' . $this->formatAmount($totalIncome) . " {$currency}";
            $lines[] = '';
        }

        if (!empty($expensesByCategory)) {
            $lines[] = '<b>📉 Расходы по категориям</b>';
            foreach ($expensesByCategory as $category => $categoryItems) {
                $emoji = $this->getCategoryEmoji($category);
                $sum = array_sum(array_column($categoryItems, 'amount'));
                $lines[] = "• {$emoji} {$category}: " . $this->formatAmount($sum) . " {$currency}";
                if ($verbose) {
                    foreach ($categoryItems as $item) {
                        $lines[] = $this->formatItemLine($item, $currency);
                    }
                }
            }
            $lines[] = '<b>Итого:</b> ' . $this->formatAmount($totalExpenses) . " {$currency}";
            $lines[] = '';
        }

        if (!empty($totalWalletBalance)) {
            $lines[] = '';
            $lines[] = '<b>💰 Балансы</b>';
            $balanceSum = 0;
            foreach ($totalWalletBalance as $wallet => $item) {
                $amount = $item['amount'];
                $balanceSum += $amount;
                $lines[] = "• {$wallet}: " . $this->formatAmount($amount) . " {$currency}";
            }

            $maxBalanceMessageId = max(array_column($totalWalletBalance, 'message_id'));
            $adjustments = 0;
            foreach ($items as $item) {
                if (($item['message_id'] ?? 0) > $maxBalanceMessageId && $item['type'] !== 'balance') {
                    $adjustments += ($item['type'] === 'income') ? $item['amount'] : -$item['amount'];
                }
            }

            if ($adjustments != 0) {
                $currentBalance = $balanceSum + $adjustments;
                $lines[] = '';
                $lines[] = "<b>Итого остаток:</b> " . $this->formatAmount($currentBalance) . " {$currency}";
            }
        }

        return implode("\n", $lines);
    }

    private function calculateWalletBalances(array $balances): array
    {
        $walletLatest = [];

        foreach ($balances as $item) {
            $wallet = $item['wallet'] ?? 'default';
            $messageId = $item['message_id'] ?? 0;

            if (!isset($walletLatest[$wallet]) || $walletLatest[$wallet]['message_id'] < $messageId) {
                $walletLatest[$wallet] = $item;
            }
        }

        return $walletLatest;
    }

    private function getCategoryEmoji(string $category): string
    {
        $key = mb_strtolower($category);

        return self::CATEGORY_EMOJI[$key] ?? '📦';
    }

    private function groupByCategory(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $category = $item['category'] ?? 'другое';
            $result[$category][] = $item;
        }

        return $result;
    }

    private function formatAmount(float $amount): string
    {
        return number_format(round($amount), 0, '.', '');
    }

    private function formatItemLine(array $item, string $currency): string
    {
        $line = "  - {$item['description']}: " . $this->formatAmount($item['amount']) . " {$currency}";

        if ($item['original_currency'] !== $currency) {
            $line .= " ({$this->formatAmount($item['original_amount'])} {$item['original_currency']})";
        }

        return $line;
    }

    private function buildSystemPrompt(int $chatId, string $currency, string $categories, ?int $topicId = null): string
    {
        $globalPrompt = $this->config->get('llm.prompts.system', '');
        $customPrompt = $this->promptRepo->getPrompt($chatId, 'stats', $topicId);
        $taskPrompt = $customPrompt ?: $this->config->get('llm.prompts.stats', '');
        $fullPrompt = $globalPrompt . "\n\n" . $taskPrompt;

        return str_replace(
            ['{currency}', '{categories}'],
            [$currency, $categories],
            $fullPrompt
        );
    }

    private function formatMessagesAsJson(array $messages): array
    {
        if (empty($messages)) {
            return [];
        }

        $result = [];
        foreach ($messages as $msg) {
            $result[] = [
                'id' => $msg['id'],
                'date' => date('Y-m-d', strtotime($msg['created_at'])),
                'message' => $msg['raw_text'],
            ];
        }

        return $result;
    }

    private function formatBasicStats(array $messages, int $months): string
    {
        $count = count($messages);

        return "<b>Статистика за {$months} мес.</b> (без AI)\n\nЗаписей: {$count}";
    }

    private function checkTokenLimit(): void
    {
        $limit = $this->config->get('llm.daily_token_limit', 1000000);
        $used = $this->usageRepo->getDailyUsage();

        if ($used >= $limit) {
            throw new TokenLimitExceededException($used, $limit);
        }
    }
}
