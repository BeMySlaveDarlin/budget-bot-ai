<?php

declare(strict_types=1);

namespace App\Service\Swoole;

use App\Service\Config\Config;
use DI\Attribute\Injectable;
use DI\Container;
use Swoole\Http\Server;

#[Injectable]
class EventHandlerRegistry
{
    public function __construct(
        private Container $container,
        private Config $config
    ) {}

    public function registerAll(Server $server): void
    {
        $events = $this->config->get('swoole.events', []);

        foreach ($events as $eventName => $handlerClass) {
            $handler = $this->container->get($handlerClass);
            $server->on($eventName, $handler);
        }
    }
}
