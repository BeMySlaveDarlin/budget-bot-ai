<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateBotConfigTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS bot_config (
                id SERIAL PRIMARY KEY,
                bot_code VARCHAR(30) UNIQUE NOT NULL,
                provider_id INT REFERENCES llm_provider(id),
                is_active BOOLEAN DEFAULT TRUE,
                configuration JSONB DEFAULT '{}',
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS bot_config CASCADE");
    }
}
