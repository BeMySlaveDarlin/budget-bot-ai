<?php

declare(strict_types=1);

namespace App\Service\Error;

class AppException extends \Exception
{
    public function __construct(
        string $message,
        public int $statusCode = 500,
        public array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
