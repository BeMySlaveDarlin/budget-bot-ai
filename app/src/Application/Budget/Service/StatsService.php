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
        $this->llmClient = $this->llmFactory->createByCode($this->config->get('llm.default_provider', 'claude'));
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

    public function getStats(int $chatId, int $months, string $currency = 'THB', bool $verbose = false): array
    {
        $billingDay = $this->chatRepo->getBillingDay($chatId);
        $messages = $this->messageRepo->getForChat($chatId, $months, $billingDay);

        if (empty($messages)) {
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

        $tokensUsed = 0;

        if (!empty($uncategorized)) {
            $this->checkTokenLimit();

            $categories = $this->chatRepo->getCategories($chatId) ?? $this->config->get('llm.default_categories');
            $systemPrompt = $this->buildSystemPrompt($chatId, $currency, $categories);
            $context = $this->formatMessagesAsJson($uncategorized);
            $userContent = json_encode(['messages' => $context], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            try {
                $tool = $this->buildCategorizationTool();
                $request = ChatRequest::create([
                    Message::system($systemPrompt),
                    Message::user($userContent),
                ]);

                $request
                    ->withTools([$tool])
                    ->withMaxTokens((int) $this->config->get('llm.providers.claude.max_tokens', 64000));

                $response = $this->llmClient->chat($request);

                $this->usageRepo->logUsage(
                    $this->llmClient->getProviderCode(),
                    $response->usage->inputTokens,
                    $response->usage->outputTokens
                );

                $newItems = $this->extractToolData($response);
                $this->messageRepo->updateCategorization($newItems);

                $cached = array_merge($cached, $newItems);
                $tokensUsed = $response->usage->totalTokens;
            } catch (\Throwable $e) {
                $this->logger->error('Stats LLM request failed', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'chat_id' => $chatId,
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
                $callItems = $toolCall->arguments['items'] ?? [];
                array_push($items, ...$callItems);
            }
            return $items;
        }

        $text = $response->asText();
        if ($text && preg_match('/```json\s*(.*?)\s*```/s', $text, $matches)) {
            $json = json_decode($matches[1], true);

            return $json['items'] ?? $json['transactions'] ?? [];
        }

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

    private function buildSystemPrompt(int $chatId, string $currency, string $categories): string
    {
        $globalPrompt = $this->config->get('llm.prompts.system', '');

        $customPrompt = $this->promptRepo->getPrompt($chatId, 'stats');
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
