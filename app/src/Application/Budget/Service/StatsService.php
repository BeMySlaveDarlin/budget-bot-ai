<?php

declare(strict_types=1);

namespace App\Application\Budget\Service;

use App\Component\Telegram\Repository\ChatRepository;
use App\Component\Telegram\Repository\MessageRepository;
use App\Service\Settings\Repository\ChatPromptRepository;
use App\Component\ExchangeRate\ExchangeRateService;
use App\Component\LLM\Client\Contract\LLMClientInterface;
use App\Component\LLM\DTO\ChatRequest;
use App\Component\LLM\DTO\Message;
use App\Component\LLM\DTO\ToolDefinition;
use App\Component\LLM\Exception\TokenLimitExceededException;
use App\Component\LLM\LLMClientFactory;
use App\Component\LLM\Repository\LlmUsageRepository;
use App\Service\Config\Config;
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

        $this->checkTokenLimit();

        $categories = $this->chatRepo->getCategories($chatId)
            ?? $this->config->get('llm.default_categories');

        $systemPrompt = $this->buildSystemPrompt($chatId, $currency, $categories);
        $context = $this->formatMessagesAsJson($messages);

        $userContent = json_encode([
            'messages' => $context,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $tool = $this->buildCategorizationTool();
            $request = ChatRequest::create([
                Message::system($systemPrompt),
                Message::user($userContent),
            ])->withTools([$tool]);

            $response = $this->llmClient->chat($request);

            $this->usageRepo->logUsage(
                $this->llmClient->getProviderCode(),
                $response->usage->inputTokens,
                $response->usage->outputTokens
            );

            $items = $this->extractToolData($response);
            $converted = $this->convertCurrencies($items, $currency);
            $statsText = $this->formatStats($converted, $currency, $verbose);

            $totalTokens = $response->usage->totalTokens;

            $planningPeriod = $this->chatRepo->getPlanningPeriod($chatId);
            $advice = $this->getAdvice($statsText, $currency, $planningPeriod);
            if ($advice['text']) {
                $statsText .= "\n\n💡 " . $advice['text'];
                $totalTokens += $advice['tokens'];

                $this->usageRepo->logUsage(
                    $this->llmClient->getProviderCode(),
                    (int) ($advice['tokens'] * 0.3),
                    (int) ($advice['tokens'] * 0.7)
                );
            }

            return [
                'text' => $statsText,
                'tokens' => $totalTokens,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Stats LLM request failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'chat_id' => $chatId,
            ]);

            return [
                'text' => $this->formatBasicStats($messages, $months),
                'tokens' => 0,
            ];
        }
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
                                'type' => [
                                    'type' => 'string',
                                    'enum' => ['income', 'expense'],
                                    'description' => 'Тип: income (доход) или expense (расход)',
                                ],
                                'category' => ['type' => 'string', 'description' => 'Категория'],
                                'amount' => ['type' => 'number', 'description' => 'Сумма как в тексте'],
                                'currency' => ['type' => 'string', 'description' => 'Валюта позиции'],
                                'description' => ['type' => 'string', 'description' => 'Описание из текста'],
                            ],
                            'required' => ['type', 'category', 'amount', 'currency', 'description'],
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
            return $response->toolCalls[0]->arguments['items'] ?? [];
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
                'type' => $item['type'] ?? 'expense',
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

        $incomeByCategory = $this->groupByCategory($income);
        $expensesByCategory = $this->groupByCategory($expenses);

        $totalIncome = array_sum(array_column($income, 'amount'));
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        $balance = $totalIncome - $totalExpenses;

        $lines = [];

        if (!empty($incomeByCategory)) {
            $lines[] = '<b>📈 Доходы</b>';
            foreach ($incomeByCategory as $category => $categoryItems) {
                $emoji = $this->getCategoryEmoji($category);
                $sum = array_sum(array_column($categoryItems, 'amount'));
                $lines[] = "• {$emoji} {$category}: " . $this->formatAmount($sum) . " {$currency}";
                if ($verbose) {
                    foreach ($categoryItems as $item) {
                        $lines[] = $this->formatItemLine($item, $currency);
                    }
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

        $sign = $balance >= 0 ? '+' : '';
        $lines[] = "<b>💰 Баланс:</b> {$sign}" . $this->formatAmount($balance) . " {$currency}";

        return implode("\n", $lines);
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

    private function getAdvice(string $statsText, string $currency, int $planningPeriod): array
    {
        try {
            $prompt = $this->config->get('llm.prompts.advices', '');
            $prompt = str_replace(
                ['{currency}', '{planning_period}'],
                [$currency, $planningPeriod],
                $prompt
            );

            $request = ChatRequest::create([
                Message::system($prompt),
                Message::user($statsText),
            ]);

            $response = $this->llmClient->chat($request);

            return [
                'text' => trim($response->asText()),
                'tokens' => $response->usage->totalTokens,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Advice LLM request failed', ['error' => $e->getMessage()]);
            return ['text' => '', 'tokens' => 0];
        }
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
        $limit = $this->config->get('llm.daily_token_limit', 100000);
        $used = $this->usageRepo->getDailyUsage();

        if ($used >= $limit) {
            throw new TokenLimitExceededException($used, $limit);
        }
    }
}
