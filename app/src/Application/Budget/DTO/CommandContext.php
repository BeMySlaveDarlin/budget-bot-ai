<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

readonly class CommandContext
{
    public function __construct(
        public array $chat,
        public array $user,
        public string $command,
        public string $args,
        public int $telegramChatId,
        public int $messageId,
        public bool $isAdmin = false,
        public bool $isEnabled = true
    ) {
    }

    public function getChatId(): int
    {
        return (int) ($this->chat['id'] ?? 0);
    }

    public function getUserId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    public function getTelegramUserId(): int
    {
        return (int) ($this->user['telegram_id'] ?? 0);
    }

    public function getCurrency(): string
    {
        return $this->chat['settings']['currency'] ?? 'THB';
    }
}
