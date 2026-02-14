<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class AddTopicIdToChatPrompts implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("ALTER TABLE chat_prompts ADD COLUMN IF NOT EXISTS topic_id INT");
        $this->db->execute("ALTER TABLE chat_prompts DROP CONSTRAINT IF EXISTS chat_prompts_chat_id_prompt_type_key");
        $this->db->execute("ALTER TABLE chat_prompts DROP CONSTRAINT IF EXISTS uq_chat_prompts_chat_type");
        $this->db->execute("CREATE UNIQUE INDEX IF NOT EXISTS uq_chat_prompts_scope ON chat_prompts (chat_id, COALESCE(topic_id, 0), prompt_type)");
    }

    public function down(): void
    {
        $this->db->execute("DROP INDEX IF EXISTS uq_chat_prompts_scope");
        $this->db->execute("ALTER TABLE chat_prompts ADD CONSTRAINT chat_prompts_chat_id_prompt_type_key UNIQUE (chat_id, prompt_type)");
        $this->db->execute("ALTER TABLE chat_prompts DROP COLUMN IF EXISTS topic_id");
    }
}
