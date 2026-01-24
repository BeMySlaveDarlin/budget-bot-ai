<?php

declare(strict_types=1);

namespace App\Service\RateLimit\Contract;

interface RateLimiterInterface
{
    public function canExecute(string $key, int $tokens = 1): bool;
    public function consumeTokens(string $key, int $tokens = 1): bool;
    public function getRemainingTokens(string $key): int;
    public function reset(string $key): void;
}
