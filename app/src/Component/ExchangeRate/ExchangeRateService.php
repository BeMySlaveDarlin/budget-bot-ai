<?php

declare(strict_types=1);

namespace App\Component\ExchangeRate;

use App\Component\ExchangeRate\Repository\CustomExchangeRateRepository;
use App\Component\ExchangeRate\Repository\ExchangeRateRepository;
use App\Service\Config\Config;
use DI\Attribute\Injectable;

#[Injectable]
class ExchangeRateService
{
    private const string BASE_CURRENCY = 'USD';

    private array $currencies;
    private array $cryptoCurrencies;

    public function __construct(
        private ExchangeRateClientFactory $clientFactory,
        private ExchangeRateRepository $repository,
        private CustomExchangeRateRepository $customRepository,
        private Config $config
    ) {
        $this->currencies = $this->config->get('exchangerate.currencies', ['USD', 'EUR', 'THB']);
        $this->cryptoCurrencies = $this->config->get('exchangerate.coingecko.currencies', ['BTC', 'ETH', 'TRX', 'USDT']);
    }

    public function updateRates(): array
    {
        $client = $this->clientFactory->create();
        $response = $client->fetchRates(self::BASE_CURRENCY);

        $updated = [];

        foreach ($this->currencies as $currency) {
            if ($currency === self::BASE_CURRENCY) {
                continue;
            }

            $rate = $response->getRate($currency);

            if ($rate !== null && $rate > 0) {
                $this->repository->upsert($currency, self::BASE_CURRENCY, $rate, $response->provider);
                $updated[$currency] = $rate;
            }
        }

        return $updated;
    }

    public function updateCryptoRates(): array
    {
        $client = $this->clientFactory->createCryptoClient();
        $response = $client->fetchRates(self::BASE_CURRENCY);

        $updated = [];

        foreach ($this->cryptoCurrencies as $currency) {
            $rate = $response->getRate($currency);

            if ($rate !== null && $rate > 0) {
                $this->repository->upsert($currency, self::BASE_CURRENCY, $rate, $response->provider);
                $updated[$currency] = $rate;
            }
        }

        return $updated;
    }

    public function updateAllRates(): array
    {
        $fiatRates = $this->updateRates();
        $cryptoRates = $this->updateCryptoRates();

        return [
            'fiat' => $fiatRates,
            'crypto' => $cryptoRates,
        ];
    }

    public function getRateToUsd(string $currency): ?float
    {
        if ($currency === self::BASE_CURRENCY) {
            return 1.0;
        }

        return $this->repository->getRate($currency, self::BASE_CURRENCY);
    }

    public function convert(float $amount, string $fromCurrency, string $toCurrency): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $fromRate = $this->getRateToUsd($fromCurrency);
        $toRate = $this->getRateToUsd($toCurrency);

        if ($fromRate === null || $toRate === null) {
            return null;
        }

        $amountInUsd = $amount / $fromRate;

        return $amountInUsd * $toRate;
    }

    public function convertForChat(float $amount, string $fromCurrency, string $toCurrency, int $chatId): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $customRate = $this->customRepository->getRate($chatId, $fromCurrency, $toCurrency);
        if ($customRate !== null) {
            return $amount * $customRate;
        }

        $reverseRate = $this->customRepository->getRate($chatId, $toCurrency, $fromCurrency);
        if ($reverseRate !== null && $reverseRate > 0) {
            return $amount / $reverseRate;
        }

        return $this->convert($amount, $fromCurrency, $toCurrency);
    }

    public function getAllRatesToUsd(): array
    {
        return $this->repository->getAllRates(self::BASE_CURRENCY);
    }

    public function getLastUpdate(): ?string
    {
        return $this->repository->getLastUpdate();
    }

    public function formatRatesForDisplay(string $displayCurrency = 'THB'): string
    {
        $rates = $this->getAllRatesToUsd();
        $lastUpdate = $this->getLastUpdate();

        if (empty($rates)) {
            return "Курсы валют не загружены. Запустите <code>exchange:update</code>";
        }

        $displayRate = $this->getRateToUsd($displayCurrency);
        if ($displayRate === null) {
            $displayRate = 1.0;
            $displayCurrency = self::BASE_CURRENCY;
        }

        $lines = ["<b>Курсы валют к {$displayCurrency}</b>"];
        $lines[] = "";

        foreach ($rates as $rate) {
            $rateToDisplay = $displayRate / (float) $rate['rate'];
            $formatted = number_format($rateToDisplay, 2);
            $lines[] = "1 {$rate['currency_from']} = {$formatted} {$displayCurrency}";
        }

        if ($displayCurrency !== self::BASE_CURRENCY) {
            $lines[] = "1 USD = " . number_format($displayRate, 2) . " {$displayCurrency}";
        }

        if ($lastUpdate) {
            $lines[] = "";
            $lines[] = "<i>Обновлено: {$lastUpdate}</i>";
        }

        return implode("\n", $lines);
    }

    public function formatRatesForAI(string $displayCurrency = 'THB'): array
    {
        $rates = $this->getAllRatesToUsd();
        $displayRate = $this->getRateToUsd($displayCurrency) ?? 1.0;
        $result = [$displayCurrency => 1];

        foreach ($rates as $rate) {
            $code = $rate['currency_from'];
            if ($code === $displayCurrency) {
                continue;
            }
            $result[$code] = round($displayRate / (float) $rate['rate'], 2);
        }

        if ($displayCurrency !== 'USD') {
            $result['USD'] = round($displayRate, 2);
        }

        return $result;
    }

    public function getBaseCurrency(): string
    {
        return self::BASE_CURRENCY;
    }
}
