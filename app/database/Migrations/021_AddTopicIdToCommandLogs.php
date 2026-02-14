<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class AddTopicIdToCommandLogs implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("ALTER TABLE command_logs ADD COLUMN IF NOT EXISTS topic_id INT");
    }

    public function down(): void
    {
        $this->db->execute("ALTER TABLE command_logs DROP COLUMN IF EXISTS topic_id");
    }
}
