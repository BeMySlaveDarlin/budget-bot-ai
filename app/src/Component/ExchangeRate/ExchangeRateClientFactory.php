<?php

declare(strict_types=1);

namespace App\Component\ExchangeRate;

use App\Component\ExchangeRate\Client\CoinGeckoClient;
use App\Component\ExchangeRate\Client\Contract\ExchangeRateClientInterface;
use App\Component\ExchangeRate\Client\ExchangeRateApiClient;
use App\Component\ExchangeRate\Exception\ExchangeRateException;
use App\Service\Config\Config;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[Injectable]
class ExchangeRateClientFactory
{
    private array $clients = [];

    public function __construct(
        private readonly Config $config,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function create(string $provider = 'exchangerate-api'): ExchangeRateClientInterface
    {
        if (isset($this->clients[$provider])) {
            return $this->clients[$provider];
        }

        $this->clients[$provider] = match ($provider) {
            'exchangerate-api' => $this->createFiatClient(),
            'coingecko' => $this->createCryptoClient(),
            default => throw ExchangeRateException::providerNotConfigured($provider),
        };

        return $this->clients[$provider];
    }

    public function createFiatClient(): ExchangeRateApiClient
    {
        $apiKey = $this->config->get('exchangerate.api_key', '');

        if (empty($apiKey)) {
            throw ExchangeRateException::apiKeyMissing('exchangerate-api');
        }

        return new ExchangeRateApiClient(
            [
                'api_url' => $this->config->get('exchangerate.api_url', 'https://v6.exchangerate-api.com/v6'),
                'timeout_seconds' => $this->config->get('exchangerate.timeout', 10),
            ],
            $apiKey,
            $this->logger ?? new NullLogger()
        );
    }

    public function createCryptoClient(): CoinGeckoClient
    {
        return new CoinGeckoClient(
            [
                'api_url' => $this->config->get('exchangerate.coingecko.api_url', 'https://api.coingecko.com/api/v3'),
                'timeout_seconds' => $this->config->get('exchangerate.coingecko.timeout', 10),
            ],
            $this->config->get('exchangerate.coingecko.api_key', ''),
            $this->logger ?? new NullLogger()
        );
    }

    public function healthCheck(): array
    {
        $results = [];

        foreach (['exchangerate-api', 'coingecko'] as $provider) {
            try {
                $client = $this->create($provider);
                $results[$provider] = $client->ping();
            } catch (\Throwable $e) {
                $results[$provider] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function isConfigured(): bool
    {
        return !empty($this->config->get('exchangerate.api_key', ''));
    }
}
