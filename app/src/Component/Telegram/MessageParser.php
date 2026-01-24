<?php

declare(strict_types=1);

namespace App\Component\Telegram;

use App\Service\Config\Config;

class MessageParser
{
    private const array CURRENCIES = ['THB', 'USD', 'EUR', 'RUB', 'USDT', 'BTC'];

    public function __construct(
        private Config $config
    ) {}

    public function parse(string $text): array
    {
        $amount = $this->extractAmount($text);
        $currency = $this->extractCurrency($text);

        return [
            'amount' => $amount,
            'currency' => $currency,
            'text' => $this->cleanText($text, $amount, $currency),
        ];
    }

    private function extractAmount(string $text): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*/', $text, $matches)) {
            return (float)str_replace(',', '.', $matches[1]);
        }
        return null;
    }

    private function extractCurrency(string $text): string
    {
        $upper = strtoupper($text);
        foreach (self::CURRENCIES as $currency) {
            if (str_contains($upper, $currency)) {
                return $currency;
            }
        }
        return $this->config->get('app.default_currency', 'THB');
    }

    private function cleanText(string $text, ?float $amount, string $currency): string
    {
        $clean = $text;

        if ($amount !== null) {
            $clean = preg_replace('/\d+(?:[.,]\d+)?\s*/', '', $clean, 1);
        }

        foreach (self::CURRENCIES as $cur) {
            $clean = preg_replace('/\b' . $cur . '\b/i', '', $clean);
        }

        return trim($clean);
    }
}
