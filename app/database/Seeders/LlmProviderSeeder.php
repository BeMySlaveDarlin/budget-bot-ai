<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Service\Database\DatabaseConnection;

class LlmProviderSeeder implements SeederInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function run(): void
    {
        $providers = [
            [
                'code' => 'claude',
                'name' => 'Claude (Anthropic)',
                'type' => 'api',
                'api_endpoint' => 'https://api.anthropic.com/v1/messages',
                'env_key_name' => 'ANTHROPIC_API_KEY',
                'model_name' => 'claude-4-5-haiku-latest',
                'supports_functions' => true,
                'supports_streaming' => true,
                'max_tokens' => 4000,
                'max_context_tokens' => 200000,
                'rate_limit_per_minute' => 60,
                'default_temperature' => 0.7,
                'is_active' => true,
            ],
            [
                'code' => 'openai',
                'name' => 'OpenAI GPT',
                'type' => 'api',
                'api_endpoint' => 'https://api.openai.com/v1/chat/completions',
                'env_key_name' => 'OPENAI_API_KEY',
                'model_name' => 'gpt-5o-mini',
                'supports_functions' => true,
                'supports_streaming' => true,
                'max_tokens' => 4000,
                'max_context_tokens' => 128000,
                'rate_limit_per_minute' => 60,
                'default_temperature' => 0.7,
                'is_active' => true,
            ],
        ];

        foreach ($providers as $provider) {
            $this->db->execute(
                "
                INSERT INTO llm_provider (
                    code, name, type, api_endpoint, env_key_name, model_name,
                    supports_functions, supports_streaming, max_tokens, max_context_tokens,
                    rate_limit_per_minute, default_temperature, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (code) DO UPDATE SET
                    name = EXCLUDED.name,
                    api_endpoint = EXCLUDED.api_endpoint,
                    model_name = EXCLUDED.model_name,
                    updated_at = NOW()
            ",
                [
                    $provider['code'],
                    $provider['name'],
                    $provider['type'],
                    $provider['api_endpoint'],
                    $provider['env_key_name'],
                    $provider['model_name'],
                    $provider['supports_functions'],
                    $provider['supports_streaming'],
                    $provider['max_tokens'],
                    $provider['max_context_tokens'],
                    $provider['rate_limit_per_minute'],
                    $provider['default_temperature'],
                    $provider['is_active'],
                ]
            );
        }

        echo "Seeded LLM providers.\n";
    }
}
