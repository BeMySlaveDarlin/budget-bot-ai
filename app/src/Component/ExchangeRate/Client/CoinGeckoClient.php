<?php

declare(strict_types=1);

namespace App\Component\ExchangeRate\Client;

use App\Component\ExchangeRate\DTO\RateResponse;
use App\Component\ExchangeRate\Exception\ExchangeRateException;

class CoinGeckoClient extends AbstractExchangeRateClient
{
    private const string BASE_URL = 'https://api.coingecko.com/api/v3';

    private const array CRYPTO_IDS = [
        'BTC' => 'bitcoin',
        'ETH' => 'ethereum',
        'TRX' => 'tron',
        'USDT' => 'tether',
    ];

    public function getProviderCode(): string
    {
        return 'coingecko';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    protected function getEndpoint(string $baseCurrency): string
    {
        $ids = implode(',', self::CRYPTO_IDS);
        $vsCurrency = strtolower($baseCurrency);
        $baseUrl = $this->config['api_url'] ?? self::BASE_URL;

        $url = "{$baseUrl}/simple/price?ids={$ids}&vs_currencies={$vsCurrency}";

        if (!empty($this->apiKey)) {
            $url .= "&x_cg_demo_api_key={$this->apiKey}";
        }

        return $url;
    }

    protected function parseResponse(array $response, string $baseCurrency): RateResponse
    {
        $vsCurrency = strtolower($baseCurrency);
        $rates = [];

        foreach (self::CRYPTO_IDS as $symbol => $coinId) {
            if (isset($response[$coinId][$vsCurrency])) {
                $priceInUsd = (float) $response[$coinId][$vsCurrency];
                $rates[$symbol] = $priceInUsd > 0 ? 1 / $priceInUsd : 0;
            }
        }

        if (empty($rates)) {
            throw ExchangeRateException::invalidResponse(
                $this->getProviderCode(),
                'No crypto rates found in response'
            );
        }

        return new RateResponse(
            baseCurrency: $baseCurrency,
            rates: $rates,
            provider: $this->getProviderCode(),
            fetchedAt: new \DateTimeImmutable()
        );
    }

    public function getSupportedCurrencies(): array
    {
        return array_keys(self::CRYPTO_IDS);
    }
}
