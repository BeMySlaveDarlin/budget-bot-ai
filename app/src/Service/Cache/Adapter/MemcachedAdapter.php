<?php

declare(strict_types=1);

namespace App\Service\Cache\Adapter;

use App\Service\Cache\CacheInterface;
use App\Service\Config\Config;
use DI\Attribute\Injectable;
use Memcached;

#[Injectable]
final class MemcachedAdapter implements CacheInterface
{
    private ?Memcached $memcached = null;
    private bool $connected = false;
    private array $config;

    public function __construct(
        private Config $configuration
    ) {
        $this->config = $this->configuration->get('cache.tiers.l2', []);
    }

    public function get(string $key): mixed
    {
        if (!$this->connect()) {
            return null;
        }

        $result = $this->memcached->get($key);
        return $result === false && $this->memcached->getResultCode() !== Memcached::RES_SUCCESS ? null : $result;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $expiration = $ttl ?? $this->configuration->get('cache.default_ttl', 3600);
        $expiration = $expiration > 0 ? time() + $expiration : 0;

        return $this->memcached->set($key, $value, $expiration);
    }

    public function delete(string $key): bool
    {
        if (!$this->connect()) {
            return false;
        }

        return $this->memcached->delete($key);
    }

    public function exists(string $key): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $this->memcached->get($key);
        return $this->memcached->getResultCode() === Memcached::RES_SUCCESS;
    }

    public function getMultiple(array $keys): array
    {
        if (!$this->connect() || empty($keys)) {
            return [];
        }

        $result = $this->memcached->getMulti($keys);
        return $result === false ? [] : $result;
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        if (!$this->connect() || empty($values)) {
            return false;
        }

        $expiration = $ttl ?? $this->configuration->get('cache.default_ttl', 3600);
        $expiration = $expiration > 0 ? time() + $expiration : 0;

        return $this->memcached->setMulti($values, $expiration);
    }

    public function deleteMultiple(array $keys): bool
    {
        if (!$this->connect() || empty($keys)) {
            return false;
        }

        $results = $this->memcached->deleteMulti($keys);
        return !in_array(false, $results, true);
    }

    public function clear(): bool
    {
        if (!$this->connect()) {
            return false;
        }

        return $this->memcached->flush();
    }

    public function increment(string $key, int $step = 1): int|false
    {
        if (!$this->connect()) {
            return false;
        }

        $result = $this->memcached->increment($key, $step);
        return $result !== false ? (int) $result : false;
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        if (!$this->connect()) {
            return false;
        }

        $result = $this->memcached->decrement($key, $step);
        return $result !== false ? (int) $result : false;
    }

    private function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        if (!extension_loaded('memcached')) {
            return false;
        }

        try {
            $persistentId = $this->config['persistent_id'] ?? null;
            $this->memcached = new Memcached($persistentId);

            $this->memcached->setOptions([
                Memcached::OPT_BINARY_PROTOCOL => true,
                Memcached::OPT_NO_BLOCK => true,
                Memcached::OPT_TCP_NODELAY => true,
                Memcached::OPT_LIBKETAMA_COMPATIBLE => true,
                Memcached::OPT_COMPRESSION => true,
                Memcached::OPT_SERIALIZER => Memcached::SERIALIZER_PHP,
            ]);

            if (empty($this->memcached->getServerList())) {
                $host = $this->config['host'] ?? 'localhost';
                $port = $this->config['port'] ?? 11211;

                if (!$this->memcached->addServer($host, $port)) {
                    return false;
                }
            }

            $version = $this->memcached->getVersion();
            $this->connected = !empty($version);

            return $this->connected;
        } catch (\Throwable $e) {
            $this->connected = false;
            return false;
        }
    }

    public function gets(string $key): ?array
    {
        if (!$this->connect()) {
            return null;
        }

        $result = $this->memcached->get($key, null, Memcached::GET_EXTENDED);
        if ($result === false) {
            return null;
        }

        return [
            'value' => $result['value'],
            'cas_token' => $result['cas']
        ];
    }

    public function cas(string $key, mixed $value, mixed $casToken, ?int $ttl = null): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $expiration = $ttl ?? $this->configuration->get('cache.default_ttl', 3600);
        $expiration = $expiration > 0 ? time() + $expiration : 0;

        return $this->memcached->cas($casToken, $key, $value, $expiration);
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $expiration = $ttl ?? $this->configuration->get('cache.default_ttl', 3600);
        $expiration = $expiration > 0 ? time() + $expiration : 0;

        return $this->memcached->add($key, $value, $expiration);
    }
}
