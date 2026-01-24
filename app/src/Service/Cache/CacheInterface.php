<?php

declare(strict_types=1);

namespace App\Service\Cache;

interface CacheInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function exists(string $key): bool;
    public function getMultiple(array $keys): array;
    public function setMultiple(array $values, ?int $ttl = null): bool;
    public function deleteMultiple(array $keys): bool;
    public function clear(): bool;
    public function increment(string $key, int $step = 1): int|false;
    public function decrement(string $key, int $step = 1): int|false;
    public function gets(string $key): ?array;
    public function cas(string $key, mixed $value, mixed $casToken, ?int $ttl = null): bool;
    public function add(string $key, mixed $value, ?int $ttl = null): bool;
}
