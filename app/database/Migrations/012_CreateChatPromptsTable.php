<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateChatPromptsTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE chat_prompts (
                id SERIAL PRIMARY KEY,
                chat_id INT REFERENCES telegram_chats(id) ON DELETE CASCADE,
                prompt_type VARCHAR(20) NOT NULL CHECK (prompt_type IN ('system', 'user')),
                prompt_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(chat_id, prompt_type)
            )
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS chat_prompts CASCADE");
    }
}
