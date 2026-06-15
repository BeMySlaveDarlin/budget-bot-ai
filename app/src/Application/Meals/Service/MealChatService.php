<?php

declare(strict_types=1);

namespace App\Application\Meals\Service;

use App\Application\Meals\DTO\MealReply;
use App\Application\Meals\Repository\MealCookHistoryRepository;
use App\Application\Meals\Repository\MealFactRepository;
use App\Application\Meals\Repository\MealInventoryRepository;
use App\Application\Meals\Repository\MealMessageRepository;
use App\Component\LLM\DTO\ChatRequest;
use App\Component\LLM\DTO\Message;
use App\Component\LLM\DTO\ToolCall;
use App\Component\LLM\DTO\ToolDefinition;
use App\Component\LLM\Exception\LLMException;
use App\Component\LLM\Exception\TokenLimitExceededException;
use App\Component\LLM\LLMClientFactory;
use App\Component\LLM\Repository\LlmUsageRepository;
use App\Component\Telegram\Repository\BotConfigRepository;
use App\Service\Config\Config;
use App\Service\Console\Repository\CommandLogRepository;
use App\Service\Settings\Repository\ChatPromptRepository;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;

#[Injectable]
final class MealChatService
{
    private const string BOT_CODE = 'meals';

    private const int MAX_USER_MESSAGE_LENGTH = 4096;

    private const int MAX_ROUNDS = 2;

    private const string FALLBACK_REPLY = 'Не получилось обработать сообщение, попробуй ещё раз.';

    public function __construct(
        private MealMessageRepository $messages,
        private MealInventoryRepository $inventory,
        private MealFactRepository $facts,
        private MealCookHistoryRepository $cookHistory,
        private BotConfigRepository $botConfig,
        private LLMClientFactory $llmFactory,
        private LlmUsageRepository $usage,
        private CommandLogRepository $commandLog,
        private ChatPromptRepository $prompts,
        private Config $config,
        private LoggerInterface $logger
    ) {
    }

    public function handle(int $chatId, ?int $userId, ?int $topicId, int $sessionId, string $userMessage): MealReply
    {
        $userMessage = mb_substr($userMessage, 0, self::MAX_USER_MESSAGE_LENGTH);
        $context = $this->assembleContext($chatId, $sessionId, $userMessage);
        [$client, $providerCode] = $this->resolveClient();

        $systemPrompt = $this->prompts->getPrompt($chatId, 'meals', $topicId)
            ?: $this->config->get('meals.system_prompt', '');

        $messages = [
            Message::system($systemPrompt),
            Message::user(json_encode($context, JSON_UNESCAPED_UNICODE)),
        ];

        $tools = $this->buildTools();
        $maxTokens = (int) $this->config->get('meals.max_tokens', 2000);

        $openFridge = false;
        $reply = '';
        $inTokens = 0;
        $outTokens = 0;

        try {
            for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
                $request = ChatRequest::create($messages)
                    ->withTools($tools)
                    ->withOption('tool_choice', 'auto')
                    ->withMaxTokens($maxTokens);

                $response = $client->chat($request);
                $inTokens += $response->usage->inputTokens;
                $outTokens += $response->usage->outputTokens;

                $reply = trim($response->asText());

                if (!$response->hasToolCalls()) {
                    break;
                }

                $messages[] = Message::assistantWithToolCalls($response->toolCalls, $response->content ?? '');
                foreach ($response->toolCalls as $toolCall) {
                    $result = $this->executeToolCall($toolCall, $chatId, $openFridge);
                    $messages[] = Message::tool($result, $toolCall->id, $toolCall->name);
                }
            }
        } catch (LLMException | TokenLimitExceededException $e) {
            $this->logger->error('[Meals] LLM request failed', [
                'chat_id' => $chatId,
                'session_id' => $sessionId,
                'provider' => $providerCode,
                'error' => $e->getMessage(),
            ]);
            $this->messages->create($chatId, $userId, $topicId, $sessionId, 'user', $userMessage);

            return MealReply::text(self::FALLBACK_REPLY);
        }

        $this->usage->logUsage($providerCode, $inTokens, $outTokens);
        $this->messages->create($chatId, $userId, $topicId, $sessionId, 'user', $userMessage);

        if ($reply === '' && !$openFridge) {
            $this->logger->warning('[Meals] empty LLM reply', [
                'chat_id' => $chatId,
                'session_id' => $sessionId,
                'provider' => $providerCode,
            ]);

            return MealReply::text(self::FALLBACK_REPLY);
        }

        if ($reply !== '') {
            $this->messages->create($chatId, null, $topicId, $sessionId, 'assistant', $reply);

            if ($userId !== null) {
                $this->commandLog->create($chatId, $userId, 'meals:chat', $userMessage, $reply, $inTokens, $outTokens, $topicId);
            }
        }

        $this->logger->info('[Meals] chat handled', [
            'chat_id' => $chatId,
            'session_id' => $sessionId,
            'provider' => $providerCode,
            'open_fridge' => $openFridge,
            'reply_length' => mb_strlen($reply),
        ]);

        return $openFridge
            ? MealReply::fridge($reply !== '' ? $reply : null)
            : MealReply::text($reply);
    }

    private function executeToolCall(ToolCall $toolCall, int $chatId, bool &$openFridge): string
    {
        return match ($toolCall->name) {
            'add_inventory' => $this->toolAddInventory($chatId, $toolCall->getArgument('items', [])),
            'remove_inventory' => $this->toolRemoveInventory($chatId, $toolCall->getArgument('names', [])),
            'add_fact' => $this->toolAddFact($chatId, $toolCall->getArgument('facts', [])),
            'remove_fact' => $this->toolRemoveFact($chatId, $toolCall->getArgument('facts', [])),
            'open_fridge' => $this->toolOpenFridge($openFridge),
            default => 'Неизвестный инструмент.',
        };
    }

    private function toolAddInventory(int $chatId, mixed $items): string
    {
        if (!is_array($items)) {
            return 'Нечего добавить.';
        }

        $added = [];
        foreach ($items as $item) {
            $name = $this->normalizeName(is_array($item) ? ($item['name'] ?? null) : $item);
            if ($name === null) {
                continue;
            }

            $quantity = is_array($item) && is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : null;
            $unit = is_array($item) ? $this->normalizeText($item['unit'] ?? null, 32) : null;

            $this->inventory->upsertByName($chatId, $name, true, $quantity, $unit);
            $added[] = $unit !== null && $quantity !== null ? "{$name} {$quantity} {$unit}" : $name;
        }

        return $added === [] ? 'Нечего добавить.' : 'Добавлено в холодильник: ' . implode(', ', $added);
    }

    private function toolRemoveInventory(int $chatId, mixed $names): string
    {
        if (!is_array($names)) {
            return 'Нечего убирать.';
        }

        $removed = [];
        foreach ($names as $raw) {
            $name = $this->normalizeName(is_array($raw) ? ($raw['name'] ?? null) : $raw);
            if ($name !== null && $this->inventory->setAvailabilityByName($chatId, $name, false)) {
                $removed[] = $name;
            }
        }

        return $removed === [] ? 'Таких продуктов нет в холодильнике.' : 'Убрано из холодильника: ' . implode(', ', $removed);
    }

    private function toolAddFact(int $chatId, mixed $facts): string
    {
        if (!is_array($facts)) {
            return 'Нечего запоминать.';
        }

        $saved = [];
        foreach ($facts as $raw) {
            $fact = $this->normalizeText(is_array($raw) ? ($raw['fact'] ?? null) : $raw, 500);
            if ($fact === null || $this->facts->existsActiveByText($chatId, $fact)) {
                continue;
            }

            $this->facts->create($chatId, $fact, 'chat');
            $saved[] = $fact;
        }

        return $saved === [] ? 'Уже запомнено.' : 'Запомнено: ' . implode('; ', $saved);
    }

    private function toolRemoveFact(int $chatId, mixed $facts): string
    {
        if (!is_array($facts)) {
            return 'Нечего удалять.';
        }

        $removed = [];
        foreach ($facts as $raw) {
            $fact = $this->normalizeText(is_array($raw) ? ($raw['fact'] ?? null) : $raw, 500);
            if ($fact !== null && $this->facts->deactivateByText($chatId, $fact)) {
                $removed[] = $fact;
            }
        }

        return $removed === [] ? 'Такого факта нет.' : 'Удалено из памяти: ' . implode('; ', $removed);
    }

    private function toolOpenFridge(bool &$openFridge): string
    {
        $openFridge = true;

        return 'Открываю холодильник кнопкой.';
    }

    private function buildTools(): array
    {
        return [
            new ToolDefinition(
                'add_inventory',
                'Добавить продукты в холодильник, когда пользователь говорит что купил/появилось/есть в наличии. Несколько позиций за раз.',
                [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string', 'description' => 'Название продукта'],
                                    'quantity' => ['type' => 'number', 'description' => 'Количество (опционально)'],
                                    'unit' => ['type' => 'string', 'description' => 'Единица: кг, л, шт (опционально)'],
                                ],
                                'required' => ['name'],
                            ],
                        ],
                    ],
                    'required' => ['items'],
                ],
            ),
            new ToolDefinition(
                'remove_inventory',
                'Убрать продукты из холодильника (пометить отсутствующими), когда продукт закончился/съели/выкинули.',
                [
                    'type' => 'object',
                    'properties' => [
                        'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Названия продуктов'],
                    ],
                    'required' => ['names'],
                ],
            ),
            new ToolDefinition(
                'add_fact',
                'Запомнить устойчивый факт-предпочтение или ограничение (аллергия, «не едим X», диета). НЕ для разовых желаний.',
                [
                    'type' => 'object',
                    'properties' => [
                        'facts' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Формулировки фактов'],
                    ],
                    'required' => ['facts'],
                ],
            ),
            new ToolDefinition(
                'remove_fact',
                'Удалить ранее сохранённый факт, когда ограничение/предпочтение больше не актуально («снова едим X», «забудь про Y»).',
                [
                    'type' => 'object',
                    'properties' => [
                        'facts' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Точные формулировки фактов для удаления'],
                    ],
                    'required' => ['facts'],
                ],
            ),
            new ToolDefinition(
                'open_fridge',
                'Открыть пользователю Mini App холодильника (кнопкой), когда просит показать/открыть холодильник или управлять им вручную.',
                ['type' => 'object', 'properties' => (object) []],
            ),
        ];
    }

    private function normalizeName(mixed $value): ?string
    {
        return $this->normalizeText($value, 200);
    }

    private function normalizeText(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function assembleContext(int $chatId, int $sessionId, string $userMessage): array
    {
        $window = (int) $this->config->get('meals.session_window', 20);
        $recentDays = (int) $this->config->get('meals.recent_days', 14);

        return [
            'now' => date('Y-m-d H:i, l'),
            'inventory' => $this->inventory->getForChat($chatId),
            'facts' => array_column($this->facts->getActive($chatId), 'fact'),
            'recent_dishes' => array_column($this->cookHistory->getRecent($chatId, $recentDays), 'dish_name'),
            'session_messages' => $this->messages->getSessionMessages($chatId, $sessionId, $window),
            'user_message' => $userMessage,
        ];
    }

    private function resolveClient(): array
    {
        $provider = $this->botConfig->resolveProvider(self::BOT_CODE);

        if ($provider !== null) {
            return [$this->llmFactory->createById((int) $provider['id']), (string) $provider['code']];
        }

        $default = $this->config->get('llm.default_provider', 'claude');

        return [$this->llmFactory->createByCode($default), $default];
    }
}
