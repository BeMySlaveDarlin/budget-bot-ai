<?php

declare(strict_types=1);

namespace App\Component\LLM\Exception;

use RuntimeException;
use Throwable;

class LLMException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider = '',
        public readonly int $statusCode = 0,
        public readonly array $context = [],
        public readonly ?string $errorType = null,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromApiResponse(string $provider, int $statusCode, array $errorData, ?Throwable $previous = null): self
    {
        $message = self::extractMessage($errorData);
        $errorType = $errorData['error']['type'] ?? $errorData['type'] ?? null;
        $errorCode = $errorData['error']['code'] ?? $errorData['code'] ?? null;

        if (is_int($errorCode)) {
            $errorCode = (string) $errorCode;
        }

        return new self(
            $message,
            $provider,
            $statusCode,
            $errorData,
            $errorType,
            $errorCode,
            $previous
        );
    }

    private static function extractMessage(array $data): string
    {
        $message = $data['error']['message'] ?? $data['message'] ?? $data['error'] ?? 'Unknown error';

        if (is_array($message)) {
            return implode("\n", $message);
        }

        return (string) $message;
    }

    public function isContextLengthExceeded(): bool
    {
        return $this->errorCode === 'context_length_exceeded'
               || str_contains($this->getMessage(), 'context length')
               || str_contains($this->getMessage(), 'too long');
    }

    public function isInvalidModel(): bool
    {
        return $this->errorCode === 'model_not_found'
               || ($this->errorType === 'invalid_request_error'
                   && str_contains($this->getMessage(), 'model'));
    }

    public static function connectionFailed(string $provider, Throwable $previous): self
    {
        return new self(
            "Failed to connect to {$provider} API",
            $provider,
            0,
            [],
            'connection_error',
            null,
            $previous
        );
    }

    public static function invalidResponse(string $provider, string $details): self
    {
        return new self(
            "Invalid response from {$provider}: {$details}",
            $provider,
            0,
            ['details' => $details]
        );
    }

    public static function modelNotFound(string $provider, string $model): self
    {
        return new self(
            "Model '{$model}' not found for provider {$provider}",
            $provider,
            404,
            ['model' => $model]
        );
    }

    public static function providerNotConfigured(string $code): self
    {
        return new self(
            "LLM provider '{$code}' is not configured or inactive",
            $code,
            0,
            ['provider_code' => $code]
        );
    }

    public static function apiKeyMissing(string $provider, string $envKey): self
    {
        return new self(
            "API key for {$provider} not found in environment variable {$envKey}",
            $provider,
            0,
            ['env_key' => $envKey]
        );
    }
}
