<?php

declare(strict_types=1);

namespace App\Component\ExchangeRate\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class ExchangeRateRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function upsert(string $currencyFrom, string $currencyTo, float $rate, string $source = 'exchangerate-api'): int
    {
        return $this->db->insert(
            "INSERT INTO exchange_rates (currency_from, currency_to, rate, source, fetched_at)
             VALUES (?, ?, ?, ?, NOW())
             ON CONFLICT (currency_from, currency_to) DO UPDATE
             SET rate = EXCLUDED.rate, source = EXCLUDED.source, fetched_at = NOW()",
            [$currencyFrom, $currencyTo, $rate, $source]
        );
    }

    public function getRate(string $currencyFrom, string $currencyTo = 'THB'): ?float
    {
        $result = $this->db->queryFirst(
            "SELECT rate FROM exchange_rates WHERE currency_from = ? AND currency_to = ?",
            [$currencyFrom, $currencyTo]
        );

        return $result ? (float) $result['rate'] : null;
    }

    public function getAllRates(string $currencyTo = 'THB'): array
    {
        return $this->db->query(
            "SELECT currency_from, rate, fetched_at FROM exchange_rates WHERE currency_to = ? ORDER BY currency_from",
            [$currencyTo]
        );
    }

    public function getLastUpdate(): ?string
    {
        $result = $this->db->queryFirst(
            "SELECT MAX(fetched_at) as last_update FROM exchange_rates"
        );

        return $result['last_update'] ?? null;
    }

    public function deleteAll(): void
    {
        $this->db->execute("DELETE FROM exchange_rates");
    }
}
