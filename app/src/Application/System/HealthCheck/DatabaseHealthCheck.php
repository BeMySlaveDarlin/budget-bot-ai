<?php

declare(strict_types=1);

namespace App\Application\System\HealthCheck;

use App\Application\System\HealthCheck\Contract\HealthCheckInterface;
use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function getName(): string
    {
        return 'database';
    }

    public function check(): array
    {
        try {
            $start = microtime(true);
            $result = $this->db->queryFirst("SELECT 1 as ok");
            $latency = round((microtime(true) - $start) * 1000);

            return [
                'healthy' => ($result['ok'] ?? 0) === 1,
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
