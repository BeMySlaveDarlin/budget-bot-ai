<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateLlmProviderTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE llm_provider (
                id SERIAL PRIMARY KEY,
                code VARCHAR(50) UNIQUE NOT NULL,
                name VARCHAR(100) NOT NULL,
                type VARCHAR(20) NOT NULL CHECK (type IN ('api', 'local')),
                api_endpoint TEXT,
                env_key_name VARCHAR(100),
                model_name VARCHAR(100),
                supports_functions BOOLEAN DEFAULT FALSE,
                supports_streaming BOOLEAN DEFAULT FALSE,
                max_tokens INTEGER DEFAULT 4000,
                max_context_tokens INTEGER DEFAULT 32000,
                rate_limit_per_minute INTEGER DEFAULT 60,
                default_temperature NUMERIC(3,2) DEFAULT 0.7,
                configuration JSONB DEFAULT '{}',
                is_active BOOLEAN DEFAULT TRUE,
                last_health_check TIMESTAMP,
                health_status VARCHAR(20) DEFAULT 'unknown',
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS llm_provider CASCADE");
    }
}
