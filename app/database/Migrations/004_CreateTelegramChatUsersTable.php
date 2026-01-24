<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateTelegramChatUsersTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE telegram_chat_users (
                id SERIAL PRIMARY KEY,
                chat_id INT REFERENCES telegram_chats(id) ON DELETE CASCADE,
                user_id INT REFERENCES telegram_users(id) ON DELETE CASCADE,
                is_admin BOOLEAN DEFAULT FALSE,
                joined_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(chat_id, user_id)
            )
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS telegram_chat_users CASCADE");
    }
}
