<?php

declare(strict_types=1);

namespace App\Application\System\HealthCheck;

use App\Application\System\HealthCheck\Contract\HealthCheckInterface;
use App\Component\ExchangeRate\ExchangeRateClientFactory;
use DI\Attribute\Injectable;

#[Injectable]
class ExchangeRateHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private ExchangeRateClientFactory $exchangeRateFactory
    ) {
    }

    public function getName(): string
    {
        return 'exchange_api';
    }

    public function check(): array
    {
        if (!$this->exchangeRateFactory->isConfigured()) {
            return ['healthy' => false, 'error' => 'API key not configured'];
        }

        $result = $this->exchangeRateFactory->healthCheck();

        if ($result['ok']) {
            return [
                'healthy' => true,
                'latency_ms' => $result['latency_ms'] ?? null,
                'provider' => $result['provider'] ?? null,
            ];
        }

        return ['healthy' => false, 'error' => $result['error'] ?? 'Connection failed'];
    }
}
