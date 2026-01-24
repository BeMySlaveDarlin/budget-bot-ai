<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateMessagesTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE messages (
                id SERIAL PRIMARY KEY,
                chat_id INT REFERENCES telegram_chats(id) ON DELETE CASCADE,
                user_id INT REFERENCES telegram_users(id) ON DELETE CASCADE,
                telegram_message_id BIGINT,
                raw_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $this->db->execute("CREATE INDEX idx_messages_chat_id ON messages(chat_id)");
        $this->db->execute("CREATE INDEX idx_messages_user_id ON messages(user_id)");
        $this->db->execute("CREATE INDEX idx_messages_created_at ON messages(created_at)");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS messages CASCADE");
    }
}
