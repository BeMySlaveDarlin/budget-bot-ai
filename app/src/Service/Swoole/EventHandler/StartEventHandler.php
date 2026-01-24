<?php

declare(strict_types=1);

namespace App\Service\Swoole\EventHandler;

use App\Service\Attribute\SwooleEventHandler;
use App\Service\Cache\CacheInterface;
use App\Service\Config\Config;
use App\Service\Swoole\Contract\SwooleEventHandlerInterface;
use DI\Attribute\Injectable;
use Swoole\Http\Server;

#[Injectable]
#[SwooleEventHandler('Start')]
class StartEventHandler implements SwooleEventHandlerInterface
{
    public function __construct(
        private Config $config,
        private CacheInterface $cache
    ) {
    }

    public function __invoke(mixed ...$args): void
    {
        $port = $this->config->get('swoole.port', 9501);
        $env = $this->config->getEnvironment();

        $this->clearStartupCache();

        echo "Budget Bot started\n";
        echo "Environment: {$env}\n";
        echo "Port: {$port}\n";
        echo "Press Ctrl+C to stop\n\n";
    }

    private function clearStartupCache(): void
    {
        try {
            $this->clearAttributeCache();
            $this->cache->clear();

            echo "Cache cleared on startup\n";
        } catch (\Exception $e) {
            echo "Warning: Failed to clear cache on startup: {$e->getMessage()}\n";
        }
    }

    private function clearAttributeCache(): void
    {
        try {
            $host = $this->config->get('cache.memcached.host', 'memcached');
            $port = $this->config->get('cache.memcached.port', 11211);

            $memcached = new \Memcached();
            $memcached->addServer($host, $port);

            $keys = $memcached->getAllKeys();
            if (!$keys) {
                return;
            }

            $cacheKeys = array_filter($keys, function($key) {
                return str_contains($key, 'attr_scan:') ||
                       str_contains($key, 'routes:') ||
                       str_contains($key, 'route_');
            });

            if ($cacheKeys) {
                $memcached->deleteMulti($cacheKeys);
            }
        } catch (\Exception) {
        }
    }
}
