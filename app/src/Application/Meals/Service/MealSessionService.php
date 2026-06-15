<?php

declare(strict_types=1);

namespace App\Application\Meals\Service;

use App\Service\Cache\CacheInterface;
use DI\Attribute\Injectable;

#[Injectable]
final class MealSessionService
{
    private const int TTL = 2592000;

    public function __construct(
        private CacheInterface $cache
    ) {
    }

    public function current(int $chatId): int
    {
        $value = $this->cache->get($this->key($chatId));

        if (!is_int($value) || $value < 1) {
            $this->cache->set($this->key($chatId), 1, self::TTL);

            return 1;
        }

        return $value;
    }

    public function reset(int $chatId): int
    {
        $next = $this->current($chatId) + 1;
        $this->cache->set($this->key($chatId), $next, self::TTL);

        return $next;
    }

    private function key(int $chatId): string
    {
        return "meals:session:{$chatId}";
    }
}
