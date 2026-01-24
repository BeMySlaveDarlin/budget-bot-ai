<?php

declare(strict_types=1);

namespace App\Component\ExchangeRate\Client;

use App\Component\ExchangeRate\DTO\RateResponse;
use App\Component\ExchangeRate\Exception\ExchangeRateException;

class ExchangeRateApiClient extends AbstractExchangeRateClient
{
    private const string BASE_URL = 'https://v6.exchangerate-api.com/v6';

    public function getProviderCode(): string
    {
        return 'exchangerate-api';
    }

    protected function getEndpoint(string $baseCurrency): string
    {
        $baseUrl = $this->config['api_url'] ?? self::BASE_URL;
        return "{$baseUrl}/{$this->apiKey}/latest/{$baseCurrency}";
    }

    protected function parseResponse(array $response, string $baseCurrency): RateResponse
    {
        if (($response['result'] ?? '') !== 'success') {
            $errorType = $response['error-type'] ?? 'unknown';
            throw ExchangeRateException::invalidResponse(
                $this->getProviderCode(),
                "API returned error: {$errorType}"
            );
        }

        $rates = $response['conversion_rates'] ?? [];

        return new RateResponse(
            baseCurrency: $baseCurrency,
            rates: $rates,
            provider: $this->getProviderCode(),
            fetchedAt: new \DateTimeImmutable()
        );
    }
}
