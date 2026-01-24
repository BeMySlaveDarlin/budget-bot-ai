<?php

declare(strict_types=1);

namespace App\Service\Swoole\Task\Contract;

interface TaskInterface
{
    public static function fromPayload(array $payload): static;

    public function handle(): mixed;

    public function getPayload(): array;

    public function getType(): string;

    public function getMaxRetries(): int;
}
