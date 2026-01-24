<?php

declare(strict_types=1);

namespace App\Service\Http\Handler;

use App\Service\Http\Context\HttpContext;
use App\Service\Http\Contract\ContextHandlerInterface;

class MiddlewareFallbackHandler implements ContextHandlerInterface
{
    public function handle(HttpContext $context): void
    {
        $errorData = [
            'error' => 'Internal Server Error',
            'message' => 'No handler processed the request',
            'path' => $context->getRequest()->getPath(),
            'method' => $context->getRequest()->getMethod(),
        ];

        $context
            ->getResponse()
            ->withStatus(500)
            ->withJson($errorData);
    }
}
