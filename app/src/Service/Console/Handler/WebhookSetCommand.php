<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Service\Attribute\Command;
use App\Service\Config\Config;
use App\Service\Console\Contract\CommandInterface;
use GuzzleHttp\Client;

#[Command(name: 'webhook:set', description: 'Set Telegram webhook')]
class WebhookSetCommand implements CommandInterface
{
    public function __construct(
        private Config $config
    ) {
    }

    public function execute(array $args = []): int
    {
        $url = $args[0] ?? null;

        if (empty($url)) {
            echo "Usage: webhook:set <url>\n";
            echo "Example: webhook:set https://example.com/telegram/webhook\n";

            return 1;
        }

        $token = $this->config->get('telegram.budget_bot_token');
        $secret = $this->config->get('telegram.webhook_secret', '');

        if (empty($token)) {
            echo "BUDGET_BOT_TOKEN not configured.\n";

            return 1;
        }

        try {
            $client = new Client(['timeout' => 10]);
            $params = [
                'url' => $url,
                'allowed_updates' => ['message', 'callback_query'],
            ];

            if (!empty($secret)) {
                $params['secret_token'] = $secret;
            }

            $response = $client->post(
                "https://api.telegram.org/bot{$token}/setWebhook",
                ['json' => $params]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            if ($data['ok']) {
                echo "Webhook set successfully: {$url}\n";

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
        return 'webhook:set';
    }

    public function getDescription(): string
    {
        return 'Set Telegram webhook';
    }
}
