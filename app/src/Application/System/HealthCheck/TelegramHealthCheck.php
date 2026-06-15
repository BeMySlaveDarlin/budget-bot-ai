<?php

declare(strict_types=1);

namespace App\Application\System\HealthCheck;

use App\Application\System\HealthCheck\Contract\HealthCheckInterface;
use App\Service\Config\Config;
use DI\Attribute\Injectable;
use GuzzleHttp\Client;

#[Injectable]
class TelegramHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private Config $config,
        private Client $httpClient
    ) {
    }

    public function getName(): string
    {
        return 'telegram';
    }

    public function check(): array
    {
        try {
            $token = $this->config->get('telegram.budget_bot_token', '');
            if (empty($token)) {
                return ['healthy' => false, 'error' => 'Token not configured'];
            }

            $start = microtime(true);
            $url = "https://api.telegram.org/bot{$token}/getMe";
            $res = $this->httpClient->get($url, ['timeout' => 5]);
            $latency = round((microtime(true) - $start) * 1000);

            $data = json_decode($res->getBody()->getContents(), true);

            return [
                'healthy' => ($data['ok'] ?? false) === true,
                'latency_ms' => $latency,
                'bot' => $data['result']['username'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
