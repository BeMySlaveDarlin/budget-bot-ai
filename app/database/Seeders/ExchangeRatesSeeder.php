<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Service\Database\DatabaseConnection;

class ExchangeRatesSeeder implements SeederInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function run(): void
    {
        $rates = [
            ['USD', 'THB', 35.5],
            ['EUR', 'THB', 38.5],
            ['RUB', 'THB', 0.35],
            ['CNY', 'THB', 4.9],
            ['JPY', 'THB', 0.23],
        ];

        foreach ($rates as [$from, $to, $rate]) {
            $this->db->execute("
                INSERT INTO budget_exchange_rates (currency_from, currency_to, rate, source, fetched_at)
                VALUES (?, ?, ?, 'seed', NOW())
                ON CONFLICT (currency_from, currency_to) DO UPDATE SET rate = EXCLUDED.rate, fetched_at = NOW()
            ", [$from, $to, $rate]);
        }

        echo "Seeded exchange rates (placeholder values).\n";
    }
}
