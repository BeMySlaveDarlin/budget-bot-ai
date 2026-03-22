<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateCustomExchangeRates implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS custom_exchange_rates (
                id SERIAL PRIMARY KEY,
                chat_id INT NOT NULL REFERENCES telegram_chats(id) ON DELETE CASCADE,
                currency_from VARCHAR(10) NOT NULL,
                currency_to VARCHAR(10) NOT NULL,
                rate DECIMAL(15,6) NOT NULL,
                updated_at TIMESTAMP DEFAULT NOW(),
                UNIQUE (chat_id, currency_from, currency_to)
            )
        SQL);
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS custom_exchange_rates");
    }
}
