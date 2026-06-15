<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Service\Database\DatabaseConnection;

class RenameBudgetTablesNamespace implements MigrationInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function up(): void
    {
        $this->db->execute("ALTER TABLE messages RENAME TO budget_messages");
        $this->db->execute("ALTER TABLE exchange_rates RENAME TO budget_exchange_rates");
        $this->db->execute("ALTER TABLE custom_exchange_rates RENAME TO budget_custom_exchange_rates");
    }

    public function down(): void
    {
        $this->db->execute("ALTER TABLE budget_messages RENAME TO messages");
        $this->db->execute("ALTER TABLE budget_exchange_rates RENAME TO exchange_rates");
        $this->db->execute("ALTER TABLE budget_custom_exchange_rates RENAME TO custom_exchange_rates");
    }
}
