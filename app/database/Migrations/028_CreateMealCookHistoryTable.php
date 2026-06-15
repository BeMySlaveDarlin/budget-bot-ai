<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateMealCookHistoryTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS meal_cook_history (
                id SERIAL PRIMARY KEY,
                chat_id INT REFERENCES telegram_chats(id) ON DELETE CASCADE,
                dish_name VARCHAR(300) NOT NULL,
                cooked_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $this->db->execute("
            CREATE INDEX IF NOT EXISTS idx_meal_cook_history_chat_time
                ON meal_cook_history(chat_id, cooked_at)
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS meal_cook_history CASCADE");
    }
}
