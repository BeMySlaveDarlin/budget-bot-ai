<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class ExchangeRatesHistorical implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            ALTER TABLE exchange_rates DROP CONSTRAINT IF EXISTS exchange_rates_currency_from_currency_to_key
        ");

        $this->db->execute("DROP INDEX IF EXISTS exchange_rates_pair_date_idx");

        $this->db->execute("
            ALTER TABLE exchange_rates ADD COLUMN IF NOT EXISTS rate_date DATE DEFAULT CURRENT_DATE
        ");

        $this->db->execute("
            UPDATE exchange_rates SET rate_date = DATE(fetched_at) WHERE rate_date IS NULL
        ");

        $this->db->execute("
            ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_pair_date_uniq
            UNIQUE (currency_from, currency_to, rate_date)
        ");

        $this->db->execute("
            CREATE INDEX IF NOT EXISTS exchange_rates_fetched_at_idx ON exchange_rates (fetched_at DESC)
        ");
    }

    public function down(): void
    {
        $this->db->execute("ALTER TABLE exchange_rates DROP CONSTRAINT IF EXISTS exchange_rates_pair_date_uniq");
        $this->db->execute("DROP INDEX IF EXISTS exchange_rates_fetched_at_idx");
        $this->db->execute("ALTER TABLE exchange_rates DROP COLUMN IF EXISTS rate_date");
        $this->db->execute("
            ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_currency_from_currency_to_key
            UNIQUE (currency_from, currency_to)
        ");
    }
}
