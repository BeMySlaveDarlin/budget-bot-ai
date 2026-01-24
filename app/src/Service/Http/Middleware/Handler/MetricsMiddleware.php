<?php

declare(strict_types=1);

namespace App\Service\Http\Middleware\Handler;

use App\Service\Attribute\Middleware;
use App\Service\Http\Context\HttpContext;
use App\Service\Http\Contract\ContextHandlerInterface;
use App\Service\Http\Contract\ContextMiddlewareInterface;
use App\Service\Metrics\MetricsCollector;

#[Middleware(priority: 95)]
final class MetricsMiddleware implements ContextMiddlewareInterface
{
    private const array EXCLUDED_PATHS = ['/metrics', '/health', '/favicon.ico'];

    public function __construct(
        private readonly MetricsCollector $metricsCollector
    ) {
    }

    public function process(HttpContext $context, ContextHandlerInterface $handler): void
    {
        $request = $context->getRequest();
        $path = $request->getPath();

        if (in_array($path, self::EXCLUDED_PATHS, true)) {
            $handler->handle($context);
            return;
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $handler->handle($context);
        } catch (\Throwable $e) {
            $this->recordMetrics($context, $startTime, $startMemory, 500);
            throw $e;
        }

        $statusCode = $context->getResponse()->getStatusCode();
        $this->recordMetrics($context, $startTime, $startMemory, $statusCode);
    }

    private function recordMetrics(
        HttpContext $context,
        float $startTime,
        int $startMemory,
        int $statusCode
    ): void {
        $request = $context->getRequest();
        $method = $request->getMethod();
        $path = $this->normalizePath($request->getPath());

        $duration = microtime(true) - $startTime;
        $memoryUsed = memory_get_usage() - $startMemory;

        $this->metricsCollector->incrementHttpRequests($method, $path, $statusCode);
        $this->metricsCollector->observeHttpDuration($duration, $method, $path);

        if ($memoryUsed > 0) {
            $this->metricsCollector->observeMemoryUsage($memoryUsed);
        }
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('/\/\d+/', '/{id}', $path);
        $path = preg_replace('/\/[a-f0-9-]{36}/', '/{uuid}', $path);
        $path = preg_replace('/\/[a-f0-9]{32}/', '/{hash}', $path);

        return $path ?: '/';
    }
}
