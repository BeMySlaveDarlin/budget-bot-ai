<?php

declare(strict_types=1);

namespace App\Service\Swoole\Contract;

interface SwooleEventHandlerInterface
{
    public function __invoke(mixed ...$args): void;
}
