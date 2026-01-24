<?php

declare(strict_types=1);

namespace App\Component\LLM\Exception;

use Throwable;

class AuthException extends LLMException
{
    public function __construct(
        string $provider,
        string $message = '',
        int $statusCode = 401,
        ?Throwable $previous = null
    ) {
        $errorMessage = $message ?: "Authentication failed for {$provider}";

        parent::__construct(
            $errorMessage,
            $provider,
            $statusCode,
            [],
            null,
            null,
            $previous
        );
    }

    public static function invalidApiKey(string $provider): self
    {
        return new self(
            $provider,
            "Invalid API key for {$provider}",
            401
        );
    }

    public static function forbidden(string $provider, string $reason = ''): self
    {
        $message = "Access forbidden for {$provider}";
        if ($reason) {
            $message .= ": {$reason}";
        }

        return new self($provider, $message, 403);
    }

    public static function quotaExceeded(string $provider): self
    {
        return new self(
            $provider,
            "API quota exceeded for {$provider}",
            402
        );
    }
}
