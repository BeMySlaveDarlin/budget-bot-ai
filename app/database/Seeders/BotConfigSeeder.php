<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Service\Database\DatabaseConnection;

class BotConfigSeeder implements SeederInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function run(): void
    {
        $bindings = [
            ['budget', 'claude'],
            ['meals', 'openrouter-gemma'],
        ];

        foreach ($bindings as [$botCode, $providerCode]) {
            $providerId = $this->db->queryColumn(
                "SELECT id FROM llm_provider WHERE code = ?",
                [$providerCode]
            );

            if ($providerId === null || $providerId === false) {
                throw new \RuntimeException(
                    "BotConfigSeeder: LLM provider '{$providerCode}' not found. Run LlmProviderSeeder first."
                );
            }

            $this->db->execute(
                "
                INSERT INTO bot_config (bot_code, provider_id, is_active)
                VALUES (?, ?, TRUE)
                ON CONFLICT (bot_code) DO UPDATE SET
                    provider_id = EXCLUDED.provider_id,
                    is_active = EXCLUDED.is_active,
                    updated_at = NOW()
            ",
                [$botCode, (int) $providerId]
            );
        }

        echo "Seeded bot config.\n";
    }
}
