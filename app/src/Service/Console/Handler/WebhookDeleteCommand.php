<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Service\Attribute\Command;
use App\Service\Config\Config;
use App\Service\Console\Contract\CommandInterface;
use GuzzleHttp\Client;

#[Command(name: 'webhook:delete', description: 'Delete Telegram webhook')]
class WebhookDeleteCommand implements CommandInterface
{
    public function __construct(
        private Config $config
    ) {
    }

    public function execute(array $args = []): int
    {
        $token = $this->config->get('telegram.budget_bot_token');

        if (empty($token)) {
            echo "BUDGET_BOT_TOKEN not configured.\n";

            return 1;
        }

        try {
            $client = new Client(['timeout' => 10]);
            $response = $client->post(
                "https://api.telegram.org/bot{$token}/deleteWebhook",
                ['json' => ['drop_pending_updates' => false]]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            if ($data['ok']) {
                echo "Webhook deleted successfully.\n";

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
        return 'webhook:delete';
    }

    public function getDescription(): string
    {
        return 'Delete Telegram webhook';
    }
}
