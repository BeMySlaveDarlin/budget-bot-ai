<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class ExtendChatPromptsConstraintMeals implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            ALTER TABLE chat_prompts
            DROP CONSTRAINT chat_prompts_prompt_type_check
        ");

        $this->db->execute("
            ALTER TABLE chat_prompts
            ADD CONSTRAINT chat_prompts_prompt_type_check
            CHECK (prompt_type IN ('stats', 'ai', 'meals'))
        ");
    }

    public function down(): void
    {
        $this->db->execute("
            ALTER TABLE chat_prompts
            DROP CONSTRAINT chat_prompts_prompt_type_check
        ");

        $this->db->execute("
            ALTER TABLE chat_prompts
            ADD CONSTRAINT chat_prompts_prompt_type_check
            CHECK (prompt_type IN ('stats', 'ai'))
        ");
    }
}
