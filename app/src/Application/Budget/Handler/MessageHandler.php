<?php

declare(strict_types=1);

namespace App\Application\Budget\Handler;

use App\Component\Telegram\Repository\MessageRepository;
use DI\Attribute\Injectable;

#[Injectable]
class MessageHandler
{
    public function __construct(
        private MessageRepository $messageRepo
    ) {
    }

    public function handle(int $chatId, int $userId, int $messageId, string $text): void
    {
        if (!preg_match('/\d/', $text)) {
            return;
        }

        $this->messageRepo->create($chatId, $userId, $messageId, $text);
    }

    public function handleEdit(int $chatId, int $telegramMessageId, string $text): void
    {
        $this->messageRepo->updateText($chatId, $telegramMessageId, $text);
    }

    public function handleDelete(int $chatId, int $telegramMessageId): bool
    {
        return $this->messageRepo->deleteByTelegramMessageId($chatId, $telegramMessageId);
    }
}
