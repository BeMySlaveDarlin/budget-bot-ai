<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateMealMessagesTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS meal_messages (
                id SERIAL PRIMARY KEY,
                chat_id INT REFERENCES telegram_chats(id) ON DELETE CASCADE,
                user_id INT REFERENCES telegram_users(id) ON DELETE SET NULL,
                topic_id INT NULL,
                session_id INT NULL,
                role VARCHAR(12) NOT NULL,
                content TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                extracted_at TIMESTAMP NULL
            )
        ");

        $this->db->execute("
            CREATE INDEX IF NOT EXISTS idx_meal_messages_unextracted
                ON meal_messages(chat_id) WHERE extracted_at IS NULL
        ");

        $this->db->execute("
            CREATE INDEX IF NOT EXISTS idx_meal_messages_session
                ON meal_messages(session_id)
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS meal_messages CASCADE");
    }
}
