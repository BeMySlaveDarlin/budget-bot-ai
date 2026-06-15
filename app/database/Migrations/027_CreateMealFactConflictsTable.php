<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateMealFactConflictsTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS meal_fact_conflicts (
                id SERIAL PRIMARY KEY,
                chat_id INT REFERENCES telegram_chats(id) ON DELETE CASCADE,
                old_fact_id INT NULL REFERENCES meal_facts(id),
                proposed_fact TEXT NOT NULL,
                recommendation VARCHAR(12) NOT NULL DEFAULT 'accept_new',
                source_note TEXT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT NOW(),
                resolved_at TIMESTAMP NULL
            )
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS meal_fact_conflicts CASCADE");
    }
}
