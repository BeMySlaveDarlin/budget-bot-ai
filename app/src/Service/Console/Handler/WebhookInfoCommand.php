<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;
use App\Service\Config\Config;
use GuzzleHttp\Client;

#[Command(name: 'webhook:info', description: 'Get Telegram webhook info')]
class WebhookInfoCommand implements CommandInterface
{
    public function __construct(
        private Config $config
    ) {
    }

    public function execute(array $args = []): int
    {
        $token = $this->config->get('telegram.bot_token');

        if (empty($token)) {
            echo "TELEGRAM_BOT_TOKEN not configured.\n";
            return 1;
        }

        try {
            $client = new Client(['timeout' => 10]);
            $response = $client->get(
                "https://api.telegram.org/bot{$token}/getWebhookInfo"
            );

            $data = json_decode($response->getBody()->getContents(), true);

            if ($data['ok']) {
                $info = $data['result'];
                echo "Webhook Info:\n";
                echo str_repeat('-', 40) . "\n";
                echo "URL: " . ($info['url'] ?: '(not set)') . "\n";
                echo "Has custom certificate: " . ($info['has_custom_certificate'] ? 'Yes' : 'No') . "\n";
                echo "Pending update count: " . ($info['pending_update_count'] ?? 0) . "\n";

                if (!empty($info['last_error_message'])) {
                    echo "Last error: " . $info['last_error_message'] . "\n";
                    echo "Last error date: " . date('Y-m-d H:i:s', $info['last_error_date']) . "\n";
                }

                if (!empty($info['allowed_updates'])) {
                    echo "Allowed updates: " . implode(', ', $info['allowed_updates']) . "\n";
                }

                return 0;
            } else {
                echo "Error: " . ($data['description'] ?? 'Unknown error') . "\n";
                return 1;
            }
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return 1;
        }
    }

    public function getName(): string
    {
        return 'webhook:info';
    }

    public function getDescription(): string
    {
        return 'Get Telegram webhook info';
    }
}
