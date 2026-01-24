<?php

declare(strict_types=1);

namespace App\Component\ExchangeRate\Client\Contract;

use App\Component\ExchangeRate\DTO\RateResponse;

interface ExchangeRateClientInterface
{
    public function fetchRates(string $baseCurrency = 'USD'): RateResponse;

    public function ping(): array;

    public function isConfigured(): bool;

    public function getProviderCode(): string;
}
