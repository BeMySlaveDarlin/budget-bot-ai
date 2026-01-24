<?php

declare(strict_types=1);

namespace App\Service\Http\Handler;

use App\Service\Http\Context\HttpContext;
use App\Service\Http\Contract\ContextHandlerInterface;
use App\Service\Http\Contract\ContextMiddlewareInterface;

readonly class ContextMiddlewareHandler implements ContextHandlerInterface
{
    public function __construct(
        private ContextMiddlewareInterface $middleware,
        private ContextHandlerInterface $next,
    ) {
    }

    public function handle(HttpContext $context): void
    {
        $this->middleware->process($context, $this->next);
    }
}
