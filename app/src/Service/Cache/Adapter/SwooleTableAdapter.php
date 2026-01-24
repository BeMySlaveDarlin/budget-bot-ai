<?php

declare(strict_types=1);

namespace App\Service\Cache\Adapter;

use App\Service\Cache\CacheInterface;
use App\Service\Config\Config;
use DI\Attribute\Injectable;
use Swoole\Table;

#[Injectable]
final class SwooleTableAdapter implements CacheInterface
{
    private ?Table $table = null;
    private array $config;

    public function __construct(
        private Config $configuration
    ) {
        $this->config = $this->configuration->get('cache.tiers.l1', []);
        $this->initializeTable();
    }

    public function get(string $key): mixed
    {
        if (!$this->table) {
            return null;
        }

        $row = $this->table->get($key);
        if ($row === false) {
            return null;
        }

        if ($row['expires_at'] > 0 && $row['expires_at'] < time()) {
            $this->table->del($key);
            return null;
        }

        return $this->unserialize($row['value']);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->table) {
            return false;
        }

        $serialized = $this->serialize($value);
        if ($serialized === null) {
            return false;
        }

        $maxSize = $this->config['max_value_size'] ?? 65535;
        if (strlen($serialized) > $maxSize) {
            return false;
        }

        $ttl = $ttl ?? $this->configuration->get('cache.default_ttl', 3600);
        $expiresAt = $ttl > 0 ? time() + $ttl : 0;

        return $this->table->set($key, [
            'value' => $serialized,
            'expires_at' => $expiresAt,
            'created_at' => time(),
        ]);
    }

    public function delete(string $key): bool
    {
        if (!$this->table) {
            return false;
        }

        return $this->table->del($key);
    }

    public function exists(string $key): bool
    {
        if (!$this->table) {
            return false;
        }

        $row = $this->table->get($key);
        if ($row === false) {
            return false;
        }

        if ($row['expires_at'] > 0 && $row['expires_at'] < time()) {
            $this->table->del($key);
            return false;
        }

        return true;
    }

    public function getMultiple(array $keys): array
    {
        if (!$this->table || empty($keys)) {
            return [];
        }

        $result = [];
        foreach ($keys as $key) {
            $value = $this->get($key);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        if (!$this->table || empty($values)) {
            return false;
        }

        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(array $keys): bool
    {
        if (!$this->table || empty($keys)) {
            return false;
        }

        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function clear(): bool
    {
        if (!$this->table) {
            return false;
        }

        foreach ($this->table as $key => $row) {
            $this->table->del($key);
        }

        return true;
    }

    public function increment(string $key, int $step = 1): int|false
    {
        if (!$this->table) {
            return false;
        }

        $current = $this->get($key);
        if ($current === null || !is_numeric($current)) {
            $current = 0;
        }

        $newValue = (int) $current + $step;
        return $this->set($key, $newValue) ? $newValue : false;
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return $this->increment($key, -$step);
    }

    public function cleanup(): int
    {
        if (!$this->table) {
            return 0;
        }

        $cleaned = 0;
        $currentTime = time();

        foreach ($this->table as $key => $row) {
            if ($row['expires_at'] > 0 && $row['expires_at'] < $currentTime) {
                $this->table->del($key);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    public function getStats(): array
    {
        if (!$this->table) {
            return [];
        }

        $total = 0;
        $expired = 0;
        $currentTime = time();

        foreach ($this->table as $key => $row) {
            $total++;
            if ($row['expires_at'] > 0 && $row['expires_at'] < $currentTime) {
                $expired++;
            }
        }

        return [
            'total_entries' => $total,
            'expired_entries' => $expired,
            'active_entries' => $total - $expired,
            'max_size' => $this->config['max_size'] ?? 1024,
            'memory_size' => $this->table->getMemorySize(),
        ];
    }

    private function initializeTable(): void
    {
        if (!extension_loaded('swoole')) {
            return;
        }

        $maxSize = $this->config['max_size'] ?? 1024;
        $maxValueSize = $this->config['max_value_size'] ?? 65535;

        $this->table = new Table($maxSize);
        $this->table->column('value', Table::TYPE_STRING, $maxValueSize);
        $this->table->column('expires_at', Table::TYPE_INT);
        $this->table->column('created_at', Table::TYPE_INT);
        $this->table->create();
    }

    private function serialize(mixed $value): ?string
    {
        try {
            return serialize($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function unserialize(string $value): mixed
    {
        try {
            return unserialize($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function gets(string $key): ?array
    {
        if (!$this->table || !$this->table->exists($key)) {
            return null;
        }

        $row = $this->table->get($key);
        if (!$row) {
            return null;
        }

        if ($row['expires_at'] > 0 && $row['expires_at'] < time()) {
            $this->table->del($key);
            return null;
        }

        return [
            'value' => $this->unserialize($row['value']),
            'cas_token' => $row['created_at']
        ];
    }

    public function cas(string $key, mixed $value, mixed $casToken, ?int $ttl = null): bool
    {
        if (!$this->table || !$this->table->exists($key)) {
            return false;
        }

        $row = $this->table->get($key);
        if (!$row || $row['created_at'] !== $casToken) {
            return false;
        }

        return $this->table->set($key, [
            'value' => $this->serialize($value),
            'expires_at' => $ttl ? time() + $ttl : 0,
            'created_at' => (int) microtime(true),
        ]);
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->table || $this->table->exists($key)) {
            return false;
        }

        return $this->table->set($key, [
            'value' => $this->serialize($value),
            'expires_at' => $ttl ? time() + $ttl : 0,
            'created_at' => (int) microtime(true),
        ]);
    }
}
