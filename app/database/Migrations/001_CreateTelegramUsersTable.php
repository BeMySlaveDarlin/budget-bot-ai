<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateTelegramUsersTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE telegram_users (
                id SERIAL PRIMARY KEY,
                telegram_id BIGINT UNIQUE NOT NULL,
                username VARCHAR(255),
                first_name VARCHAR(255),
                last_name VARCHAR(255),
                language_code VARCHAR(10),
                is_premium BOOLEAN DEFAULT FALSE,
                settings JSONB DEFAULT '{}',
                enabled BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $this->db->execute("CREATE INDEX idx_telegram_users_telegram_id ON telegram_users(telegram_id)");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS telegram_users CASCADE");
    }
}
