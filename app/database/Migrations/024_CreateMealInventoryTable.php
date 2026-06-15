<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateMealInventoryTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS meal_inventory (
                id SERIAL PRIMARY KEY,
                chat_id INT REFERENCES telegram_chats(id) ON DELETE CASCADE,
                name VARCHAR(200) NOT NULL,
                available BOOLEAN NOT NULL DEFAULT TRUE,
                quantity NUMERIC NULL,
                unit VARCHAR(30) NULL,
                updated_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(chat_id, name)
            )
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS meal_inventory CASCADE");
    }
}
