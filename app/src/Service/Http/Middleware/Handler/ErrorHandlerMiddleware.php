<?php

declare(strict_types=1);

namespace App\Service\Http\Middleware\Handler;

use App\Service\Attribute\Middleware;
use App\Service\Error\ErrorHandler;
use App\Service\Http\Context\HttpContext;
use App\Service\Http\Contract\ContextHandlerInterface;
use App\Service\Http\Contract\ContextMiddlewareInterface;
use Psr\Log\LoggerInterface;

#[Middleware(priority: 100)]
final class ErrorHandlerMiddleware implements ContextMiddlewareInterface
{
    public function __construct(
        private ErrorHandler $errorHandler,
        private LoggerInterface $logger
    ) {
    }

    public function process(HttpContext $context, ContextHandlerInterface $handler): void
    {
        try {
            $handler->handle($context);
        } catch (\Throwable $e) {
            $this->logger->error('HTTP request error', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => [
                    'method' => $context->getRequest()->getMethod(),
                    'path' => $context->getRequest()->getPath(),
                    'query' => $context->getRequest()->getQueryParams(),
                ],
            ]);

            $errorData = $this->errorHandler->handle($e);

            $context
                ->getResponse()
                ->withStatus($errorData['code'])
                ->withJson($errorData);
        }
    }
}
