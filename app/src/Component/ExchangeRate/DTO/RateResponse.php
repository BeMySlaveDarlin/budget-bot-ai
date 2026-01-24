<?php

declare(strict_types=1);

namespace App\Component\ExchangeRate\DTO;

readonly class RateResponse
{
    public function __construct(
        public string $baseCurrency,
        public array $rates,
        public string $provider,
        public ?\DateTimeImmutable $fetchedAt = null
    ) {
    }

    public function getRate(string $currency): ?float
    {
        return $this->rates[$currency] ?? null;
    }

    public function hasRate(string $currency): bool
    {
        return isset($this->rates[$currency]);
    }

    public function toArray(): array
    {
        return [
            'base_currency' => $this->baseCurrency,
            'rates' => $this->rates,
            'provider' => $this->provider,
            'fetched_at' => $this->fetchedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
