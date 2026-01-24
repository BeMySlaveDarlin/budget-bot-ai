<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Component\ExchangeRate\ExchangeRateService;
use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;

#[Command(name: 'exchange:update', description: 'Update exchange rates (fiat and crypto)')]
class ExchangeUpdateCommand implements CommandInterface
{
    public function __construct(
        private ExchangeRateService $exchangeRateService
    ) {
    }

    public function execute(array $args = []): int
    {
        $type = $args[0] ?? 'all';

        try {
            return match ($type) {
                'fiat' => $this->updateFiat(),
                'crypto' => $this->updateCrypto(),
                default => $this->updateAll(),
            };
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return 1;
        }
    }

    private function updateFiat(): int
    {
        echo "Fetching fiat rates (base: USD)...\n";
        $updated = $this->exchangeRateService->updateRates();
        return $this->printResults($updated, 'fiat');
    }

    private function updateCrypto(): int
    {
        echo "Fetching crypto rates (base: USD)...\n";
        $updated = $this->exchangeRateService->updateCryptoRates();
        return $this->printResults($updated, 'crypto');
    }

    private function updateAll(): int
    {
        echo "Fetching all rates (base: USD)...\n\n";

        $result = $this->exchangeRateService->updateAllRates();
        $total = 0;

        if (!empty($result['fiat'])) {
            echo "Fiat currencies:\n";
            foreach ($result['fiat'] as $currency => $rate) {
                echo "  1 USD = " . number_format($rate, 4) . " {$currency}\n";
            }
            $total += count($result['fiat']);
        }

        if (!empty($result['crypto'])) {
            echo "\nCrypto currencies:\n";
            foreach ($result['crypto'] as $currency => $rate) {
                $formatted = $rate < 0.0001 ? sprintf('%.8f', $rate) : number_format($rate, 6);
                echo "  1 USD = {$formatted} {$currency}\n";
            }
            $total += count($result['crypto']);
        }

        echo "\nUpdated {$total} exchange rates.\n";
        return $total > 0 ? 0 : 1;
    }

    private function printResults(array $updated, string $type): int
    {
        if (empty($updated)) {
            echo "No {$type} rates updated.\n";
            return 1;
        }

        foreach ($updated as $currency => $rate) {
            $formatted = $rate < 0.0001 ? sprintf('%.8f', $rate) : number_format($rate, 4);
            echo "  1 USD = {$formatted} {$currency}\n";
        }

        echo "\nUpdated " . count($updated) . " {$type} rates.\n";
        return 0;
    }

    public function getName(): string
    {
        return 'exchange:update';
    }

    public function getDescription(): string
    {
        return 'Update exchange rates (fiat and crypto)';
    }
}
