<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateMealFactsTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS meal_facts (
                id SERIAL PRIMARY KEY,
                chat_id INT REFERENCES telegram_chats(id) ON DELETE CASCADE,
                fact TEXT NOT NULL,
                source VARCHAR(12) NOT NULL DEFAULT 'extracted',
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                superseded_by INT NULL REFERENCES meal_facts(id),
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS meal_facts CASCADE");
    }
}
