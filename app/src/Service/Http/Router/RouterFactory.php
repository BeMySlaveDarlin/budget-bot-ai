<?php

declare(strict_types=1);

namespace App\Service\Http\Router;

use App\Service\Attribute\AttributeScanner;
use App\Service\Config\Config;
use DI\Attribute\Injectable;
use FastRoute\Dispatcher;

use function FastRoute\simpleDispatcher;

#[Injectable]
class RouterFactory
{
    public function __construct(
        private Config $config,
        private AttributeScanner $scanner
    ) {
    }

    public function create(): Dispatcher
    {
        $collector = new RouteCollector($this->scanner);

        $handlers = $this->config->get('routes.handlers', []);
        $collector->collectFromAttributes($handlers);

        $directories = $this->config->get('routes.directories', []);
        $collector->collectFromDirectories($directories);

        return simpleDispatcher(function (\FastRoute\RouteCollector $r) use ($collector) {
            foreach ($collector->getRoutes() as $route) {
                $r->addRoute($route->method, $route->path, $route->handler);
            }
        });
    }
}
