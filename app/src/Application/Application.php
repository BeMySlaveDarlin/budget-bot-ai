<?php

declare(strict_types=1);

namespace App\Application;

use App\Service\Swoole\EventHandler\ShutdownEventHandler;
use App\Service\Swoole\EventHandlerRegistry;
use DI\Attribute\Injectable;
use Swoole\Http\Server;

#[Injectable]
class Application
{
    public function __construct(
        private Server $server,
        private EventHandlerRegistry $eventRegistry,
        private ShutdownEventHandler $shutdownHandler
    ) {
        $this->eventRegistry->registerAll($this->server);
        $this->shutdownHandler->register($this->server);
    }

    public function start(): void
    {
        $this->server->start();
    }
}
