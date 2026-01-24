<?php

declare(strict_types=1);

namespace App\Application\System\HealthCheck;

use App\Application\System\HealthCheck\Contract\HealthCheckInterface;
use App\Service\Cache\CacheInterface;
use DI\Attribute\Injectable;

#[Injectable]
class CacheHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private CacheInterface $cache
    ) {
    }

    public function getName(): string
    {
        return 'cache';
    }

    public function check(): array
    {
        try {
            $key = 'health_check_' . uniqid('', true);
            $this->cache->set($key, 'ok', 10);
            $value = $this->cache->get($key);
            $this->cache->delete($key);

            $stats = $this->cache->getStats();

            return [
                'healthy' => $value === 'ok',
                'l1_enabled' => $stats['l1_enabled'] ?? false,
                'l2_enabled' => $stats['l2_enabled'] ?? false,
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
