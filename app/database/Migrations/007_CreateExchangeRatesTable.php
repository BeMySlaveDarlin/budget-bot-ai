<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateExchangeRatesTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE exchange_rates (
                id SERIAL PRIMARY KEY,
                currency_from VARCHAR(10) NOT NULL,
                currency_to VARCHAR(10) DEFAULT 'THB',
                rate DECIMAL(15,6) NOT NULL,
                source VARCHAR(50) DEFAULT 'exchangerate-api',
                fetched_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(currency_from, currency_to)
            )
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS exchange_rates CASCADE");
    }
}
