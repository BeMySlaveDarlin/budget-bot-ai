<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class CreateTelegramUpdatesTable implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE telegram_updates (
                id BIGSERIAL PRIMARY KEY,
                update_id BIGINT UNIQUE NOT NULL,
                type VARCHAR(50) NOT NULL,
                raw_json JSONB NOT NULL,
                processed BOOLEAN DEFAULT FALSE,
                result JSONB,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $this->db->execute("CREATE INDEX idx_telegram_updates_update_id ON telegram_updates(update_id)");
        $this->db->execute("CREATE INDEX idx_telegram_updates_processed ON telegram_updates(processed)");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS telegram_updates CASCADE");
    }
}
