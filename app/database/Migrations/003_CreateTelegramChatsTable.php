<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateTelegramChatsTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE telegram_chats (
                id SERIAL PRIMARY KEY,
                telegram_chat_id BIGINT UNIQUE NOT NULL,
                title VARCHAR(255),
                type VARCHAR(50),
                description TEXT,
                invite_link VARCHAR(255),
                is_active BOOLEAN DEFAULT TRUE,
                settings JSONB DEFAULT '{}',
                llm_provider_id INT REFERENCES llm_provider(id) ON DELETE SET NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $this->db->execute("CREATE INDEX idx_telegram_chats_telegram_chat_id ON telegram_chats(telegram_chat_id)");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS telegram_chats CASCADE");
    }
}
