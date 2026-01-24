<?php

declare(strict_types=1);

namespace App\Component\ExchangeRate\Exception;

use RuntimeException;
use Throwable;

class ExchangeRateException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider = '',
        public readonly int $statusCode = 0,
        public readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function apiKeyMissing(string $provider): self
    {
        return new self(
            "API key for {$provider} not configured",
            $provider,
            0,
            ['error_type' => 'api_key_missing']
        );
    }

    public static function connectionFailed(string $provider, Throwable $previous): self
    {
        return new self(
            "Failed to connect to {$provider} API: " . $previous->getMessage(),
            $provider,
            0,
            ['error_type' => 'connection_failed'],
            $previous
        );
    }

    public static function invalidResponse(string $provider, string $details): self
    {
        return new self(
            "Invalid response from {$provider}: {$details}",
            $provider,
            0,
            ['error_type' => 'invalid_response', 'details' => $details]
        );
    }

    public static function rateLimited(string $provider): self
    {
        return new self(
            "Rate limit exceeded for {$provider}",
            $provider,
            429,
            ['error_type' => 'rate_limited']
        );
    }

    public static function providerNotConfigured(string $code): self
    {
        return new self(
            "Exchange rate provider '{$code}' is not configured",
            $code,
            0,
            ['error_type' => 'provider_not_configured']
        );
    }
}
