<?php

declare(strict_types=1);

namespace App\Application\Meals\Service;

use App\Component\Telegram\Repository\BotConfigRepository;
use App\Application\Meals\Repository\MealCookHistoryRepository;
use App\Application\Meals\Repository\MealFactRepository;
use App\Application\Meals\Repository\MealInventoryRepository;
use App\Application\Meals\Repository\MealMessageRepository;
use App\Component\LLM\DTO\ChatRequest;
use App\Component\LLM\DTO\Message;
use App\Component\LLM\Exception\LLMException;
use App\Component\LLM\Exception\TokenLimitExceededException;
use App\Component\LLM\LLMClientFactory;
use App\Component\LLM\Repository\LlmUsageRepository;
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

    public function handle(int $chatId, ?int $userId, ?int $topicId, int $sessionId, string $userMessage): string
    {
        $userMessage = mb_substr($userMessage, 0, self::MAX_USER_MESSAGE_LENGTH);
        $context = $this->assembleContext($chatId, $sessionId, $userMessage);
        [$client, $providerCode] = $this->resolveClient();

        $systemPrompt = $this->prompts->getPrompt($chatId, 'meals', $topicId)
            ?: $this->config->get('meals.system_prompt', '');

        $request = ChatRequest::create([
            Message::system($systemPrompt),
            Message::user(json_encode($context, JSON_UNESCAPED_UNICODE)),
        ]);

        try {
            $response = $client->chat($request);
        } catch (LLMException | TokenLimitExceededException $e) {
            $this->logger->error('[Meals] LLM request failed', [
                'chat_id' => $chatId,
                'session_id' => $sessionId,
                'provider' => $providerCode,
                'error' => $e->getMessage(),
            ]);
            $this->messages->create($chatId, $userId, $topicId, $sessionId, 'user', $userMessage);

            return self::FALLBACK_REPLY;
        }

        $reply = trim($response->asText());

        $this->usage->logUsage(
            $providerCode,
            $response->usage->inputTokens,
            $response->usage->outputTokens
        );

        $this->messages->create($chatId, $userId, $topicId, $sessionId, 'user', $userMessage);

        if ($reply === '') {
            $this->logger->warning('[Meals] empty LLM reply', [
                'chat_id' => $chatId,
                'session_id' => $sessionId,
                'provider' => $providerCode,
            ]);

            return self::FALLBACK_REPLY;
        }

        $this->messages->create($chatId, null, $topicId, $sessionId, 'assistant', $reply);

        if ($userId !== null) {
            $this->commandLog->create(
                $chatId,
                $userId,
                'meals:chat',
                $userMessage,
                $reply,
                $response->usage->inputTokens,
                $response->usage->outputTokens,
                $topicId
            );
        }

        $this->logger->info('[Meals] chat handled', [
            'chat_id' => $chatId,
            'session_id' => $sessionId,
            'provider' => $providerCode,
            'reply_length' => mb_strlen($reply),
        ]);

        return $reply;
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
