<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class AddTopicIdToMessages implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("ALTER TABLE messages ADD COLUMN IF NOT EXISTS topic_id INT");
        $this->db->execute("CREATE INDEX IF NOT EXISTS idx_messages_chat_topic ON messages(chat_id, topic_id)");
    }

    public function down(): void
    {
        $this->db->execute("DROP INDEX IF EXISTS idx_messages_chat_topic");
        $this->db->execute("ALTER TABLE messages DROP COLUMN IF EXISTS topic_id");
    }
}
