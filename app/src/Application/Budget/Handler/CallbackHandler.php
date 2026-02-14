<?php

declare(strict_types=1);

namespace App\Application\Budget\Handler;

use App\Component\Telegram\Repository\ChatRepository;
use App\Component\Telegram\TelegramClient;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;

#[Injectable]
class CallbackHandler
{
    public function __construct(
        private ChatRepository $chatRepo,
        private TelegramClient $telegram,
        private LoggerInterface $logger
    ) {
    }

    public function handle(array $callback, ?int $topicId = null): void
    {
        $callbackId = $callback['id'];
        $data = $callback['data'] ?? '';
        $message = $callback['message'] ?? null;
        $chatTgId = $message['chat']['id'] ?? null;

        if (!$chatTgId || !$data) {
            $this->telegram->answerCallbackQuery($callbackId, 'Invalid callback');

            return;
        }

        $parts = explode(':', $data);
        $action = $parts[0] ?? '';

        $result = match ($action) {
            'settings' => $this->handleSettings($parts, $chatTgId, $topicId),
            default => ['text' => 'Unknown action', 'alert' => false],
        };

        $this->telegram->answerCallbackQuery($callbackId, $result['text'], $result['alert'] ?? false);
    }

    private function handleSettings(array $parts, int $chatTgId, ?int $topicId = null): array
    {
        $subAction = $parts[1] ?? '';
        $value = $parts[2] ?? '';

        $chat = $this->chatRepo->findByTelegramChatId($chatTgId);

        if (!$chat) {
            return ['text' => 'Chat not found', 'alert' => true];
        }

        if ($subAction === 'currency' && in_array($value, ['THB', 'USD', 'EUR', 'RUB'])) {
            $this->chatRepo->setCurrency($chat['id'], $value);

            return ['text' => "Валюта: {$value}", 'alert' => false];
        }

        if ($subAction === 'billing' && is_numeric($value)) {
            $day = (int) $value;
            $this->chatRepo->setBillingDay($chat['id'], $day);

            return ['text' => "Начало месяца: {$day}-е", 'alert' => false];
        }

        return ['text' => 'Invalid settings action', 'alert' => true];
    }
}
