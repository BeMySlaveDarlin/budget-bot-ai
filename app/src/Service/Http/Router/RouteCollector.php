<?php

declare(strict_types=1);

namespace App\Service\Http\Router;

use App\Service\Attribute\AttributeScanner;
use App\Service\Attribute\Route as RouteAttribute;
use ReflectionClass;
use ReflectionException;

class RouteCollector
{
    private array $routes = [];

    public function __construct(
        private AttributeScanner $scanner
    ) {
    }

    public function collectFromAttributes(array $handlers): void
    {
        foreach ($handlers as $handler) {
            $this->collectFromClass($handler);
        }
    }

    public function collectFromDirectories(array $directories): void
    {
        $classes = $this->scanner->scanRouteClasses($directories);

        foreach ($classes as $className) {
            $this->collectFromClass($className);
        }
    }

    private function collectFromClass(string $className): void
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException) {
            return;
        }

        $classAttributes = $reflection->getAttributes(RouteAttribute::class);
        foreach ($classAttributes as $attribute) {
            $route = $attribute->newInstance();
            foreach ($route->getMethods() as $method) {
                $this->routes[] = new RouteDto(
                    method: strtoupper($method),
                    path: $route->path,
                    handler: $className,
                    name: $route->name
                );
            }
        }

        foreach ($reflection->getMethods() as $method) {
            $methodAttributes = $method->getAttributes(RouteAttribute::class);
            foreach ($methodAttributes as $attribute) {
                $route = $attribute->newInstance();
                foreach ($route->getMethods() as $httpMethod) {
                    $this->routes[] = new RouteDto(
                        method: strtoupper($httpMethod),
                        path: $route->path,
                        handler: $className . '::' . $method->getName(),
                        name: $route->name
                    );
                }
            }
        }
    }

    public function addRoute(string $method, string $path, string $handler, ?string $name = null): void
    {
        $this->routes[] = new RouteDto(
            method: strtoupper($method),
            path: $path,
            handler: $handler,
            name: $name
        );
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }
}
