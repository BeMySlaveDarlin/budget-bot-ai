<?php

declare(strict_types=1);

namespace App\Component\ExchangeRate\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
final class CustomExchangeRateRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function upsert(int $chatId, string $currencyFrom, string $currencyTo, float $rate): void
    {
        $this->db->execute(
            <<<'SQL'
                INSERT INTO budget_custom_exchange_rates (chat_id, currency_from, currency_to, rate, updated_at)
                VALUES (?, ?, ?, ?, NOW())
                ON CONFLICT (chat_id, currency_from, currency_to) DO UPDATE
                SET rate = EXCLUDED.rate, updated_at = NOW()
            SQL,
            [$chatId, strtoupper($currencyFrom), strtoupper($currencyTo), $rate]
        );
    }

    public function getRate(int $chatId, string $currencyFrom, string $currencyTo): ?float
    {
        $row = $this->db->queryFirst(
            "SELECT rate FROM budget_custom_exchange_rates WHERE chat_id = ? AND currency_from = ? AND currency_to = ?",
            [$chatId, strtoupper($currencyFrom), strtoupper($currencyTo)]
        );

        return $row ? (float) $row['rate'] : null;
    }

    public function getAllForChat(int $chatId): array
    {
        return $this->db->query(
            "SELECT currency_from, currency_to, rate, updated_at FROM budget_custom_exchange_rates WHERE chat_id = ? ORDER BY currency_from",
            [$chatId]
        );
    }

    public function delete(int $chatId, string $currencyFrom, string $currencyTo): bool
    {
        return $this->db->delete(
            "DELETE FROM budget_custom_exchange_rates WHERE chat_id = ? AND currency_from = ? AND currency_to = ?",
            [$chatId, strtoupper($currencyFrom), strtoupper($currencyTo)]
        ) > 0;
    }
}
