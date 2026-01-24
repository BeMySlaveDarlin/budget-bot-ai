<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Service\Database\DatabaseConnection;

class SettingsSeeder implements SeederInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function run(): void
    {
        $settings = [
            'default_currency' => ['value' => 'THB'],
            'default_llm_provider' => ['value' => 'claude'],
            'exchange_update_interval' => ['value' => 3600],
            'bot_commands' => [
                'value' => [
                    'start' => 'Начать работу с ботом',
                    'stats' => 'Статистика за период',
                    'ai' => 'Задать вопрос AI о бюджете',
                ],
            ],
        ];

        foreach ($settings as $key => $data) {
            $this->db->execute("
                INSERT INTO settings (key, value) VALUES (?, ?)
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()
            ", [$key, json_encode($data['value'])]);
        }

        echo "Seeded settings.\n";
    }
}
