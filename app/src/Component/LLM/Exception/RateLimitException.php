<?php

declare(strict_types=1);

namespace App\Component\LLM\Exception;

use Throwable;

class RateLimitException extends LLMException
{
    public function __construct(
        string $provider,
        public readonly ?int $retryAfter = null,
        public readonly ?int $limitRequests = null,
        public readonly ?int $remainingRequests = null,
        public readonly ?int $limitTokens = null,
        public readonly ?int $remainingTokens = null,
        public readonly ?float $resetTime = null,
        ?Throwable $previous = null
    ) {
        $message = "Rate limit exceeded for {$provider}";
        if ($retryAfter !== null) {
            $message .= ", retry after {$retryAfter}s";
        }

        parent::__construct(
            $message,
            $provider,
            429,
            [
                'retry_after' => $retryAfter,
                'limit_requests' => $limitRequests,
                'remaining_requests' => $remainingRequests,
                'limit_tokens' => $limitTokens,
                'remaining_tokens' => $remainingTokens,
                'reset_time' => $resetTime,
            ],
            'rate_limit_error',
            'rate_limit_exceeded',
            $previous
        );
    }

    public static function create(
        string $provider,
        int $retryAfter,
        int $limit,
        int $remaining,
        ?string $context = null
    ): self {
        return new self(
            $provider,
            $retryAfter,
            $limit,
            $remaining,
            null,
            null,
            (float) $retryAfter
        );
    }

    public static function fromHeaders(string $provider, array $headers): self
    {
        $retryAfter = self::parseHeader($headers, ['retry-after', 'Retry-After']);
        $limitRequests = self::parseHeader($headers, ['x-ratelimit-limit-requests', 'x-ratelimit-limit']);
        $remainingRequests = self::parseHeader($headers, ['x-ratelimit-remaining-requests', 'x-ratelimit-remaining']);
        $limitTokens = self::parseHeader($headers, ['x-ratelimit-limit-tokens', 'anthropic-ratelimit-tokens-limit']);
        $remainingTokens = self::parseHeader($headers, ['x-ratelimit-remaining-tokens', 'anthropic-ratelimit-tokens-remaining']);
        $resetTime = self::parseResetTime($headers);

        return new self(
            $provider,
            $retryAfter,
            $limitRequests,
            $remainingRequests,
            $limitTokens,
            $remainingTokens,
            $resetTime
        );
    }

    private static function parseHeader(array $headers, array $keys): ?int
    {
        foreach ($keys as $key) {
            $lowerKey = strtolower($key);
            foreach ($headers as $headerKey => $headerValue) {
                if (strtolower($headerKey) === $lowerKey) {
                    $value = is_array($headerValue) ? ($headerValue[0] ?? null) : $headerValue;
                    if ($value !== null && is_numeric($value)) {
                        return (int) $value;
                    }
                }
            }
        }
        return null;
    }

    private static function parseResetTime(array $headers): ?float
    {
        $resetKeys = ['x-ratelimit-reset-requests', 'x-ratelimit-reset-tokens', 'anthropic-ratelimit-tokens-reset'];

        foreach ($resetKeys as $key) {
            $lowerKey = strtolower($key);
            foreach ($headers as $headerKey => $headerValue) {
                if (strtolower($headerKey) === $lowerKey) {
                    $value = is_array($headerValue) ? ($headerValue[0] ?? null) : $headerValue;
                    if ($value !== null) {
                        if (preg_match('/^[\d.]+$/', $value)) {
                            return (float) $value;
                        }
                        if (preg_match('/(\d+)ms/', $value, $m)) {
                            return (float) $m[1] / 1000;
                        }
                        if (preg_match('/(\d+)s/', $value, $m)) {
                            return (float) $m[1];
                        }
                    }
                }
            }
        }
        return null;
    }

    public function shouldRetry(): bool
    {
        if ($this->retryAfter !== null) {
            return $this->retryAfter < 60;
        }

        if ($this->resetTime !== null) {
            return $this->resetTime < 60;
        }

        return true;
    }

    public function getWaitTime(): int
    {
        if ($this->retryAfter !== null) {
            return $this->retryAfter;
        }

        if ($this->resetTime !== null) {
            return (int) ceil($this->resetTime);
        }

        return 5;
    }
}
