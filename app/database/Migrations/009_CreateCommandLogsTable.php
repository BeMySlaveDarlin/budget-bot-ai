<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateCommandLogsTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE command_logs (
                id SERIAL PRIMARY KEY,
                chat_id INT REFERENCES telegram_chats(id) ON DELETE CASCADE,
                user_id INT REFERENCES telegram_users(id) ON DELETE CASCADE,
                command VARCHAR(50),
                params TEXT,
                llm_response TEXT,
                input_tokens INT DEFAULT 0,
                output_tokens INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $this->db->execute("CREATE INDEX idx_command_logs_chat_id ON command_logs(chat_id)");
        $this->db->execute("CREATE INDEX idx_command_logs_created_at ON command_logs(created_at)");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS command_logs CASCADE");
    }
}
