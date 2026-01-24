<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Service\Attribute\Command;
use App\Service\Config\Config;
use App\Service\Console\Contract\CommandInterface;
use GuzzleHttp\Client;

#[Command(name: 'webhook:test', description: 'E2E test via real HTTP requests to webhook')]
class WebhookTestCommand implements CommandInterface
{
    private Client $http;
    private string $webhookUrl;
    private string $token;
    private int $chatId = 217708876;
    private int $userId = 217708876;
    private int $messageId = 1;

    public function __construct(
        private Config $config
    ) {
        $this->token = $this->config->get('telegram.bot_token');
        $host = $this->config->get('swoole.host', '127.0.0.1');
        $port = $this->config->get('swoole.port', 9501);
        $this->webhookUrl = "http://{$host}:{$port}/telegram/{$this->token}";

        $this->http = new Client([
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    public function execute(array $args = []): int
    {
        echo "=== E2E Webhook Test ===\n";
        echo "URL: {$this->webhookUrl}\n\n";

        $tests = [
            ['name' => '/start', 'text' => '/start'],
            ['name' => '/help', 'text' => '/help'],
            ['name' => '/rate', 'text' => '/rate'],
            ['name' => '/stats 1', 'text' => '/stats 1'],
            ['name' => '/status', 'text' => '/status'],
            ['name' => '/settings', 'text' => '/settings'],
            ['name' => 'text message', 'text' => '500 тест e2e'],
        ];

        $passed = 0;
        $failed = 0;

        foreach ($tests as $test) {
            echo "Testing: {$test['name']}... ";

            $result = $this->sendWebhook($test['text']);

            if ($result['success']) {
                echo "✅ HTTP {$result['status']}\n";
                $passed++;
            } else {
                echo "❌ {$result['error']}\n";
                $failed++;
            }

            usleep(500000);
        }

        echo "\n=== Results ===\n";
        echo "Passed: {$passed}\n";
        echo "Failed: {$failed}\n";

        return $failed > 0 ? 1 : 0;
    }

    private function sendWebhook(string $text): array
    {
        $this->messageId++;

        $payload = [
            'update_id' => time() + $this->messageId,
            'message' => [
                'message_id' => $this->messageId,
                'from' => [
                    'id' => $this->userId,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'username' => 'BeMySlaveDarlin',
                ],
                'chat' => [
                    'id' => $this->chatId,
                    'type' => 'private',
                    'first_name' => 'Test',
                    'username' => 'BeMySlaveDarlin',
                ],
                'date' => time(),
                'text' => $text,
            ],
        ];

        try {
            $response = $this->http->post($this->webhookUrl, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Telegram-Bot-Api-Secret-Token' => $this->config->get('telegram.webhook_secret', ''),
                ],
            ]);

            $status = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            return [
                'success' => $status === 200,
                'status' => $status,
                'body' => $body,
                'error' => $status !== 200 ? "HTTP {$status}: {$body}" : null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'body' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getName(): string
    {
        return 'webhook:test';
    }

    public function getDescription(): string
    {
        return 'E2E test via real HTTP requests to webhook';
    }
}
