<?php

declare(strict_types=1);

namespace App\Service\Http\Middleware;

use App\Service\Http\Context\HttpContext;
use App\Service\Http\Contract\ContextHandlerInterface;
use App\Service\Http\Contract\ContextMiddlewareInterface;
use App\Service\Http\Handler\ContextMiddlewareHandler;
use App\Service\Http\Handler\MiddlewareFallbackHandler;

class MiddlewarePipeline implements ContextHandlerInterface
{
    private array $middlewares = [];
    private ?ContextHandlerInterface $cachedStack = null;
    private ?ContextHandlerInterface $cachedFallbackHandler = null;
    private string $middlewareHash = '';

    public function pipe(ContextMiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        $this->invalidateCache();

        return $this;
    }

    public function handle(HttpContext $context): void
    {
        $this->getOrCreateStack(new MiddlewareFallbackHandler())->handle($context);
    }

    public function process(HttpContext $context, ContextHandlerInterface $handler): void
    {
        $this->getOrCreateStack($handler)->handle($context);
    }

    private function getOrCreateStack(ContextHandlerInterface $finalHandler): ContextHandlerInterface
    {
        $currentHash = $this->computeMiddlewareHash();

        if ($this->cachedStack !== null
            && $this->cachedFallbackHandler === $finalHandler
            && $this->middlewareHash === $currentHash
        ) {
            return $this->cachedStack;
        }

        $this->cachedStack = $this->buildStack($finalHandler);
        $this->cachedFallbackHandler = $finalHandler;
        $this->middlewareHash = $currentHash;

        return $this->cachedStack;
    }

    private function buildStack(ContextHandlerInterface $finalHandler): ContextHandlerInterface
    {
        $handler = $finalHandler;
        $middlewares = array_reverse($this->middlewares);

        foreach ($middlewares as $middleware) {
            $handler = new ContextMiddlewareHandler($middleware, $handler);
        }

        return $handler;
    }

    private function computeMiddlewareHash(): string
    {
        $ids = array_map(fn($m) => spl_object_id($m), $this->middlewares);
        return md5(implode('|', $ids));
    }

    private function invalidateCache(): void
    {
        $this->cachedStack = null;
        $this->cachedFallbackHandler = null;
        $this->middlewareHash = '';
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function clear(): self
    {
        $this->middlewares = [];
        $this->invalidateCache();

        return $this;
    }
}
