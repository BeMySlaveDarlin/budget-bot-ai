<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class ChangeBaseCurrencyToUsd implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("DELETE FROM exchange_rates");

        $this->db->execute("
            ALTER TABLE exchange_rates
            ALTER COLUMN currency_to SET DEFAULT 'USD'
        ");
    }

    public function down(): void
    {
        $this->db->execute("
            ALTER TABLE exchange_rates
            ALTER COLUMN currency_to SET DEFAULT 'THB'
        ");
    }
}
