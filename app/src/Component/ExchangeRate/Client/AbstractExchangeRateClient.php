<?php

declare(strict_types=1);

namespace App\Component\ExchangeRate\Client;

use App\Component\ExchangeRate\Client\Contract\ExchangeRateClientInterface;
use App\Component\ExchangeRate\DTO\RateResponse;
use App\Component\ExchangeRate\Exception\ExchangeRateException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class AbstractExchangeRateClient implements ExchangeRateClientInterface
{
    protected const int DEFAULT_TIMEOUT = 30;

    protected Client $httpClient;
    protected LoggerInterface $logger;

    public function __construct(
        protected readonly array $config,
        protected readonly string $apiKey,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->httpClient = $this->createHttpClient();
    }

    abstract public function getProviderCode(): string;

    abstract protected function getEndpoint(string $baseCurrency): string;

    abstract protected function parseResponse(array $response, string $baseCurrency): RateResponse;

    public function fetchRates(string $baseCurrency = 'USD'): RateResponse
    {
        if (!$this->isConfigured()) {
            throw ExchangeRateException::apiKeyMissing($this->getProviderCode());
        }

        $this->logger->debug('Fetching exchange rates', [
            'provider' => $this->getProviderCode(),
            'base_currency' => $baseCurrency,
        ]);

        try {
            $response = $this->httpClient->get($this->getEndpoint($baseCurrency), [
                'headers' => $this->getHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ExchangeRateException::invalidResponse(
                    $this->getProviderCode(),
                    'Invalid JSON: ' . json_last_error_msg()
                );
            }

            $result = $this->parseResponse($data, $baseCurrency);

            $this->logger->info('Exchange rates fetched', [
                'provider' => $this->getProviderCode(),
                'base_currency' => $baseCurrency,
                'rates_count' => count($result->rates),
            ]);

            return $result;
        } catch (ConnectException $e) {
            throw ExchangeRateException::connectionFailed($this->getProviderCode(), $e);
        } catch (RequestException $e) {
            $this->handleRequestException($e);
        }
    }

    public function ping(): array
    {
        try {
            $start = microtime(true);
            $response = $this->fetchRates('USD');
            $latency = round((microtime(true) - $start) * 1000);

            return [
                'ok' => true,
                'latency_ms' => $latency,
                'provider' => $this->getProviderCode(),
                'rates_count' => count($response->rates),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'provider' => $this->getProviderCode(),
            ];
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    protected function getHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Accept-Encoding' => 'identity',
        ];
    }

    protected function createHttpClient(): Client
    {
        return new Client([
            'timeout' => $this->config['timeout_seconds'] ?? self::DEFAULT_TIMEOUT,
            'verify' => true,
            'decode_content' => true,
        ]);
    }

    protected function handleRequestException(RequestException $e): never
    {
        $response = $e->getResponse();
        $statusCode = $response?->getStatusCode() ?? 0;

        $body = '';
        if ($response !== null) {
            try {
                $body = $response->getBody()->getContents();
            } catch (\Throwable) {
            }
        }

        $this->logger->error('Exchange rate request failed', [
            'provider' => $this->getProviderCode(),
            'status' => $statusCode,
            'body' => $body,
        ]);

        if ($statusCode === 429) {
            throw ExchangeRateException::rateLimited($this->getProviderCode());
        }

        throw new ExchangeRateException(
            "API request failed: " . $e->getMessage(),
            $this->getProviderCode(),
            $statusCode,
            ['body' => $body],
            $e
        );
    }
}
