<?php

declare(strict_types=1);

namespace App\Component\Telegram\WebApp;

final readonly class WebAppAccess
{
    private function __construct(
        public bool $granted,
        public int $chatId,
        public ?array $user,
        public int $denyStatus,
        public string $denyError,
    ) {
    }

    public static function grant(int $chatId, array $user): self
    {
        return new self(true, $chatId, $user, 0, '');
    }

    public static function deny(int $status, string $error): self
    {
        return new self(false, 0, null, $status, $error);
    }
}
