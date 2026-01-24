<?php

declare(strict_types=1);

namespace App\Service\Http\Router;

use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;
use DI\Attribute\Injectable;
use DI\Container;
use FastRoute\Dispatcher;

#[Injectable]
class Router
{
    private Dispatcher $dispatcher;

    public function __construct(
        private Container $container,
        private RouterFactory $routerFactory
    ) {
        $this->dispatcher = $this->routerFactory->create();
    }

    public function dispatch(Request $request, Response $response): void
    {
        $method = $request->getMethod();
        $uri = rawurldecode(parse_url($request->getUri(), PHP_URL_PATH) ?? '/');

        $routeInfo = $this->dispatcher->dispatch($method, $uri);

        match ($routeInfo[0]) {
            Dispatcher::FOUND => $this->handleFound($routeInfo, $request, $response),
            Dispatcher::NOT_FOUND => $this->handleNotFound($response),
            Dispatcher::METHOD_NOT_ALLOWED => $this->handleMethodNotAllowed($response),
        };
    }

    private function handleFound(array $routeInfo, Request $request, Response $response): void
    {
        [, $handler, $vars] = $routeInfo;

        if (is_string($handler)) {
            if (str_contains($handler, '::')) {
                [$class, $method] = explode('::', $handler);
                $instance = $this->container->get($class);
                $instance->$method($request, $response, $vars);
            } else {
                $instance = $this->container->get($handler);
                $instance->handle($request, $response, $vars);
            }
        } elseif (is_array($handler)) {
            [$class, $method] = $handler;
            $instance = $this->container->get($class);
            $instance->$method($request, $response, $vars);
        } elseif (is_callable($handler)) {
            $handler($request, $response, $vars);
        }
    }

    private function handleNotFound(Response $response): void
    {
        $response->status(404)->json(['error' => 'Not Found']);
    }

    private function handleMethodNotAllowed(Response $response): void
    {
        $response->status(405)->json(['error' => 'Method Not Allowed']);
    }
}
