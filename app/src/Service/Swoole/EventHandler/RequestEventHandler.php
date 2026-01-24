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
    public function __construct(
        private Router $router,
        private LoggerInterface $logger
    ) {}

    public function __invoke(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        Coroutine::create(function () use ($swooleRequest, $swooleResponse) {
            try {
                $request = new Request($swooleRequest);
                $response = new Response($swooleResponse);

                $this->router->dispatch($request, $response);

                $response->send();
            } catch (\Throwable $e) {
                $this->logger->error('Request handling failed', [
                    'error' => $e->getMessage(),
                    'uri' => $swooleRequest->server['request_uri'] ?? '',
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
