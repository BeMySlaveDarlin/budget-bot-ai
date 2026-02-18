<?php

declare(strict_types=1);

namespace App\Service\Swoole\EventHandler;

use App\Service\Attribute\SwooleEventHandler;
use App\Service\Http\Router\Router;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

#[Injectable]
#[SwooleEventHandler('Request')]
class RequestEventHandler
{
    private const array EXCLUDED_PATHS = ['/metrics', '/health', '/favicon.ico'];

    public function __construct(
        private Router $router,
        private LoggerInterface $logger
    ) {}

    public function __invoke(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        Coroutine::create(function () use ($swooleRequest, $swooleResponse) {
            $uri = $swooleRequest->server['request_uri'] ?? '';
            $method = $swooleRequest->server['request_method'] ?? '';
            $startTime = microtime(true);

            try {
                $request = new Request($swooleRequest);
                $response = new Response($swooleResponse);

                if (!in_array($uri, self::EXCLUDED_PATHS, true)) {
                    $this->logger->info('HTTP request', [
                        'method' => $method,
                        'uri' => $uri,
                    ]);
                }

                $this->router->dispatch($request, $response);

                $response->send();

                if (!in_array($uri, self::EXCLUDED_PATHS, true)) {
                    $duration = (microtime(true) - $startTime) * 1000;
                    $this->logger->info('HTTP response', [
                        'method' => $method,
                        'uri' => $uri,
                        'status' => $response->getStatusCode(),
                        'duration_ms' => round($duration, 2),
                    ]);
                }
            } catch (\Throwable $e) {
                $duration = (microtime(true) - $startTime) * 1000;
                $this->logger->error('Request handling failed', [
                    'method' => $method,
                    'uri' => $uri,
                    'error' => $e->getMessage(),
                    'duration_ms' => round($duration, 2),
                ]);

                $swooleResponse->status(500);
                $swooleResponse->header('Content-Type', 'application/json');
                $swooleResponse->end(json_encode([
                    'error' => 'Internal Server Error',
                    'message' => $e->getMessage(),
                ]));
            }
        });
    }
}
