<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateTasksTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE tasks (
                id BIGSERIAL PRIMARY KEY,
                type VARCHAR(255) NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                payload JSONB NOT NULL DEFAULT '{}',
                progress JSONB NOT NULL DEFAULT '{}',
                result JSONB,
                error_message TEXT,
                max_retries INT DEFAULT 3,
                retry_count INT DEFAULT 0,
                context_id INT,
                context_type VARCHAR(50),
                created_at TIMESTAMP DEFAULT NOW(),
                started_at TIMESTAMP,
                completed_at TIMESTAMP
            )
        ");

        $this->db->execute("CREATE INDEX idx_tasks_status ON tasks(status)");
        $this->db->execute("CREATE INDEX idx_tasks_type ON tasks(type)");
        $this->db->execute("CREATE INDEX idx_tasks_context ON tasks(context_type, context_id)");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS tasks CASCADE");
    }
}
