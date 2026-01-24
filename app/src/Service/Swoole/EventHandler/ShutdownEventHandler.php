<?php

declare(strict_types=1);

namespace App\Service\Swoole\EventHandler;

use App\Service\Attribute\SwooleEventHandler;
use App\Service\Swoole\Contract\SwooleEventHandlerInterface;
use DI\Attribute\Injectable;
use Swoole\Http\Server;

#[Injectable]
#[SwooleEventHandler('Shutdown')]
class ShutdownEventHandler implements SwooleEventHandlerInterface
{
    private bool $shuttingDown = false;

    public function __invoke(mixed ...$args): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;
        $server = $args[0] ?? null;

        echo "\nGraceful shutdown initiated...\n";

        echo "Stopping server...\n";
        if ($server instanceof Server) {
            $server->shutdown();
        }

        echo "Shutdown complete\n";
    }

    public function register(Server $server): void
    {
        pcntl_signal(SIGTERM, function () use ($server) {
            echo "\nReceived SIGTERM\n";
            $this($server);
        });

        pcntl_signal(SIGINT, function () use ($server) {
            echo "\nReceived SIGINT (Ctrl+C)\n";
            $this($server);
        });

        pcntl_async_signals(true);
    }
}
