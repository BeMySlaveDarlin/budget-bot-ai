<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateLlmUsageTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE llm_usage (
                id BIGSERIAL PRIMARY KEY,
                provider_id INTEGER REFERENCES llm_provider(id) ON DELETE SET NULL,
                input_tokens INTEGER DEFAULT 0,
                output_tokens INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $this->db->execute("CREATE INDEX idx_llm_usage_provider_id ON llm_usage(provider_id)");
        $this->db->execute("CREATE INDEX idx_llm_usage_created_at ON llm_usage(created_at)");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS llm_usage CASCADE");
    }
}
