<?php

declare(strict_types=1);

namespace App\Service\Http\Router;

readonly class RouteDto
{
    public function __construct(
        public string $method,
        public string $path,
        public string $handler,
        public ?string $name = null
    ) {
    }
}
