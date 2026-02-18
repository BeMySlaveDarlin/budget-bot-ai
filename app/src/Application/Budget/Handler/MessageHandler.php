<?php

declare(strict_types=1);

namespace App\Application\Budget\Handler;

use App\Component\Telegram\Repository\MessageRepository;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;

#[Injectable]
class MessageHandler
{
    public function __construct(
        private MessageRepository $messageRepo,
        private LoggerInterface $logger
    ) {
    }

    public function handle(int $chatId, int $userId, int $messageId, string $text, ?int $topicId = null): void
    {
        if (!preg_match('/\d/', $text)) {
            return;
        }

        $this->logger->info('[Message] Saving expense', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);

        $this->messageRepo->create($chatId, $userId, $messageId, $text, $topicId);
    }

    public function handleEdit(int $chatId, int $telegramMessageId, string $text, ?int $topicId = null): void
    {
        $this->logger->info('[Message] Edit', [
            'chat_id' => $chatId,
            'message_id' => $telegramMessageId,
            'topic_id' => $topicId,
        ]);

        $this->messageRepo->updateText($chatId, $telegramMessageId, $text, $topicId);
    }

    public function handleDelete(int $chatId, int $telegramMessageId, ?int $topicId = null): bool
    {
        $this->logger->info('[Message] Delete', [
            'chat_id' => $chatId,
            'message_id' => $telegramMessageId,
            'topic_id' => $topicId,
        ]);

        return $this->messageRepo->deleteByTelegramMessageId($chatId, $telegramMessageId, $topicId);
    }
}
