<?php

declare(strict_types=1);

namespace App\Service\Http\Middleware\Handler;

use App\Service\Attribute\Middleware;
use App\Service\Http\Context\HttpContext;
use App\Service\Http\Contract\ContextHandlerInterface;
use App\Service\Http\Contract\ContextMiddlewareInterface;
use Psr\Log\LoggerInterface;

#[Middleware(priority: 90)]
final class LoggingMiddleware implements ContextMiddlewareInterface
{
    private const array EXCLUDED_PATHS = ['/metrics', '/health', '/favicon.ico'];

    private const array SENSITIVE_QUERY_KEYS = ['sig', 'signature', 'hash', 'token', 'secret'];

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

        $maskedPath = $this->maskPath($path);
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $this->logger->info('HTTP request started', [
            'method' => $request->getMethod(),
            'path' => $maskedPath,
            'query' => $this->maskQuery($request->getQueryParams()),
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
                'path' => $maskedPath,
                'status' => $response->getStatusCode(),
                'duration_ms' => round($duration, 2),
                'memory_used_bytes' => $memoryUsed,
            ]);

            if ($duration > 1000) {
                $this->logger->warning('Slow HTTP request', [
                    'method' => $request->getMethod(),
                    'path' => $maskedPath,
                    'duration_ms' => round($duration, 2),
                ]);
            }
        } catch (\Throwable $e) {
            $endTime = microtime(true);
            $duration = ($endTime - $startTime) * 1000;

            $this->logger->error('HTTP request failed', [
                'method' => $request->getMethod(),
                'path' => $maskedPath,
                'duration_ms' => round($duration, 2),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function maskPath(string $path): string
    {
        return preg_replace('#^(/telegram(?:/budget|/meal)?/)[^/]+#', '$1***', $path);
    }

    /**
     * @param array<int|string, mixed> $query
     * @return array<int|string, mixed>
     */
    private function maskQuery(array $query): array
    {
        $masked = [];

        foreach ($query as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_QUERY_KEYS, true)) {
                $masked[$key] = '***';
                continue;
            }

            $masked[$key] = is_array($value) ? $this->maskQuery($value) : $value;
        }

        return $masked;
    }
}
