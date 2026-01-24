<?php

declare(strict_types=1);

namespace App\Service\Http\Middleware\Handler;

use App\Service\Attribute\Middleware;
use App\Service\Http\Context\HttpContext;
use App\Service\Http\Contract\ContextHandlerInterface;
use App\Service\Http\Contract\ContextMiddlewareInterface;
use Psr\Log\LoggerInterface;

#[Middleware(priority: 90)]
class LoggingMiddleware implements ContextMiddlewareInterface
{
    private const array EXCLUDED_PATHS = ['/metrics', '/health', '/favicon.ico'];

    public function __construct(
        private LoggerInterface $logger
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
        $startMemory = memory_get_usage(true);

        $this->logger->info('HTTP request started', [
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'query' => $request->getQueryParams(),
            'user_agent' => $request->getHeader('User-Agent'),
        ]);

        try {
            $handler->handle($context);

            $response = $context->getResponse();
            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            $duration = ($endTime - $startTime) * 1000;
            $memoryUsed = $endMemory - $startMemory;

            $this->logger->info('HTTP request completed', [
                'method' => $request->getMethod(),
                'path' => $request->getPath(),
                'status' => $response->getStatusCode(),
                'duration_ms' => round($duration, 2),
                'memory_used_bytes' => $memoryUsed,
            ]);

            if ($duration > 1000) {
                $this->logger->warning('Slow HTTP request', [
                    'method' => $request->getMethod(),
                    'path' => $request->getPath(),
                    'duration_ms' => round($duration, 2),
                ]);
            }
        } catch (\Throwable $e) {
            $endTime = microtime(true);
            $duration = ($endTime - $startTime) * 1000;

            $this->logger->error('HTTP request failed', [
                'method' => $request->getMethod(),
                'path' => $request->getPath(),
                'duration_ms' => round($duration, 2),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
