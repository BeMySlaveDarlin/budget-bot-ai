<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Application\Application;
use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;

#[Command(name: 'start', description: 'Start Swoole HTTP server')]
class StartCommand implements CommandInterface
{
    public function __construct(
        private Application $app
    ) {
    }

    public function execute(array $args = []): int
    {
        $this->app->start();

        return 0;
    }

    public function getName(): string
    {
        return 'start';
    }

    public function getDescription(): string
    {
        return 'Start Swoole HTTP server';
    }
}
