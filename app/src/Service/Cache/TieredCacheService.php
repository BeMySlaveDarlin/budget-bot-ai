<?php

declare(strict_types=1);

namespace App\Service\Cache;

use App\Service\Cache\Adapter\MemcachedAdapter;
use App\Service\Cache\Adapter\SwooleTableAdapter;
use App\Service\Config\Config;
use App\Service\Metrics\MetricsCollector;
use DI\Attribute\Injectable;

#[Injectable]
final class TieredCacheService implements CacheInterface
{
    private array $config;
    private array $stats = [
        'l1_hits' => 0,
        'l1_misses' => 0,
        'l2_hits' => 0,
        'l2_misses' => 0,
    ];

    public function __construct(
        private Config $configuration,
        private SwooleTableAdapter $l1Cache,
        private MemcachedAdapter $l2Cache,
        private ?MetricsCollector $metrics = null
    ) {
        $this->config = $this->configuration->get('cache', []);
    }

    public function get(string $key): mixed
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if ($this->isL1Enabled()) {
            $value = $this->l1Cache->get($key);
            if ($value !== null) {
                $this->incrementStat('l1_hits');
                return $value;
            }
            $this->incrementStat('l1_misses');
        }

        if ($this->isL2Enabled()) {
            $value = $this->l2Cache->get($key);
            if ($value !== null) {
                $this->incrementStat('l2_hits');

                if ($this->isL1Enabled() && ($this->config['strategies']['read_through'] ?? true)) {
                    $this->l1Cache->set($key, $value);
                }

                return $value;
            }
            $this->incrementStat('l2_misses');
        }

        return null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $success = true;

        if ($this->isL1Enabled()) {
            if (!$this->l1Cache->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        if ($this->isL2Enabled() && ($this->config['strategies']['write_through'] ?? true)) {
            if (!$this->l2Cache->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function delete(string $key): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $success = true;

        if ($this->isL1Enabled()) {
            if (!$this->l1Cache->delete($key)) {
                $success = false;
            }
        }

        if ($this->isL2Enabled()) {
            if (!$this->l2Cache->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function exists(string $key): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->isL1Enabled() && $this->l1Cache->exists($key)) {
            return true;
        }

        if ($this->isL2Enabled()) {
            return $this->l2Cache->exists($key);
        }

        return false;
    }

    public function getMultiple(array $keys): array
    {
        if (!$this->isEnabled() || empty($keys)) {
            return [];
        }

        $result = [];
        $missingKeys = $keys;

        if ($this->isL1Enabled()) {
            $l1Result = $this->l1Cache->getMultiple($keys);
            $result = array_merge($result, $l1Result);
            $missingKeys = array_diff($keys, array_keys($l1Result));
        }

        if ($this->isL2Enabled() && !empty($missingKeys)) {
            $l2Result = $this->l2Cache->getMultiple($missingKeys);
            $result = array_merge($result, $l2Result);

            if ($this->isL1Enabled() && !empty($l2Result) && ($this->config['strategies']['read_through'] ?? true)) {
                $this->l1Cache->setMultiple($l2Result);
            }
        }

        return $result;
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        if (!$this->isEnabled() || empty($values)) {
            return false;
        }

        $success = true;

        if ($this->isL1Enabled()) {
            if (!$this->l1Cache->setMultiple($values, $ttl)) {
                $success = false;
            }
        }

        if ($this->isL2Enabled() && ($this->config['strategies']['write_through'] ?? true)) {
            if (!$this->l2Cache->setMultiple($values, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(array $keys): bool
    {
        if (!$this->isEnabled() || empty($keys)) {
            return false;
        }

        $success = true;

        if ($this->isL1Enabled()) {
            if (!$this->l1Cache->deleteMultiple($keys)) {
                $success = false;
            }
        }

        if ($this->isL2Enabled()) {
            if (!$this->l2Cache->deleteMultiple($keys)) {
                $success = false;
            }
        }

        return $success;
    }

    public function clear(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $success = true;

        if ($this->isL1Enabled()) {
            if (!$this->l1Cache->clear()) {
                $success = false;
            }
        }

        if ($this->isL2Enabled()) {
            if (!$this->l2Cache->clear()) {
                $success = false;
            }
        }

        $this->stats = ['l1_hits' => 0, 'l1_misses' => 0, 'l2_hits' => 0, 'l2_misses' => 0];

        return $success;
    }

    public function increment(string $key, int $step = 1): int|false
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->isL1Enabled()) {
            $result = $this->l1Cache->increment($key, $step);
            if ($result !== false) {
                if ($this->isL2Enabled()) {
                    $this->l2Cache->set($key, $result);
                }
                return $result;
            }
        }

        if ($this->isL2Enabled()) {
            return $this->l2Cache->increment($key, $step);
        }

        return false;
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return $this->increment($key, -$step);
    }

    public function gets(string $key): ?array
    {
        if ($this->isL2Enabled()) {
            $result = $this->l2Cache->gets($key);
            if ($result !== null) {
                $this->incrementStat('l2_hits');
                if ($this->isL1Enabled()) {
                    $this->l1Cache->set($key, $result['value']);
                }
                return $result;
            }
            $this->incrementStat('l2_misses');
        }

        if (!$this->isL2Enabled() && $this->isL1Enabled()) {
            $result = $this->l1Cache->gets($key);
            if ($result !== null) {
                $this->incrementStat('l1_hits');
                return $result;
            }
            $this->incrementStat('l1_misses');
        }

        return null;
    }

    public function cas(string $key, mixed $value, mixed $casToken, ?int $ttl = null): bool
    {
        if ($this->isL2Enabled()) {
            $success = $this->l2Cache->cas($key, $value, $casToken, $ttl);
            if ($success && $this->isL1Enabled()) {
                $this->l1Cache->delete($key);
                $this->l1Cache->set($key, $value, $ttl);
            }
            return $success;
        }

        if ($this->isL1Enabled()) {
            return $this->l1Cache->cas($key, $value, $casToken, $ttl);
        }

        return false;
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $success = false;

        if ($this->isL2Enabled()) {
            $success = $this->l2Cache->add($key, $value, $ttl);
        }

        if ($success && $this->isL1Enabled()) {
            $this->l1Cache->add($key, $value, $ttl);
        }

        return $success;
    }

    public function getStats(): array
    {
        $l1Stats = $this->isL1Enabled() ? $this->l1Cache->getStats() : [];
        $totalHits = $this->stats['l1_hits'] + $this->stats['l2_hits'];
        $totalRequests = $totalHits + $this->stats['l1_misses'] + $this->stats['l2_misses'];

        return [
            'enabled' => $this->isEnabled(),
            'l1_enabled' => $this->isL1Enabled(),
            'l2_enabled' => $this->isL2Enabled(),
            'stats' => $this->stats,
            'hit_rate' => $totalRequests > 0 ? round(($totalHits / $totalRequests) * 100, 2) : 0,
            'l1_cache' => $l1Stats,
        ];
    }

    public function cleanup(): int
    {
        if (!$this->isL1Enabled()) {
            return 0;
        }

        return $this->l1Cache->cleanup();
    }

    public function warmUp(array $data): int
    {
        if (!$this->isEnabled() || empty($data)) {
            return 0;
        }

        $warmed = 0;
        foreach ($data as $key => $value) {
            if ($this->set((string) $key, $value)) {
                $warmed++;
            }
        }

        return $warmed;
    }

    private function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    private function isL1Enabled(): bool
    {
        return $this->config['tiers']['l1']['enabled'] ?? true;
    }

    private function isL2Enabled(): bool
    {
        return $this->config['tiers']['l2']['enabled'] ?? true;
    }

    private function incrementStat(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }
}
