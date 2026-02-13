<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class AddCategorizedGinIndex implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute(
            "CREATE INDEX IF NOT EXISTS idx_messages_categorized_gin ON messages USING GIN (categorized)"
        );
    }

    public function down(): void
    {
        $this->db->execute("DROP INDEX IF EXISTS idx_messages_categorized_gin");
    }
}
