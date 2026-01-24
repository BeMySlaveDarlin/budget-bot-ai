<?php

declare(strict_types=1);

namespace App\Service\Error;

class ErrorHandler
{
    public function handle(\Throwable $exception): array
    {
        if ($exception instanceof AppException) {
            return [
                'error' => $exception->getMessage(),
                'code' => $exception->statusCode,
                'context' => $exception->context,
            ];
        }

        return [
            'error' => 'Internal Server Error',
            'code' => 500,
        ];
    }

    public function renderJson(\Throwable $exception): string
    {
        $response = $this->handle($exception);

        return json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
