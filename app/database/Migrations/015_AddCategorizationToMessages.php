<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class AddCategorizationToMessages implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("ALTER TABLE messages ADD COLUMN categorized JSONB");
    }

    public function down(): void
    {
        $this->db->execute("ALTER TABLE messages DROP COLUMN IF EXISTS categorized");
    }
}
