<?php

declare(strict_types=1);

namespace App\Service\RateLimit;

use App\Service\Cache\CacheInterface;
use App\Service\Config\Config;
use App\Service\RateLimit\Contract\RateLimiterInterface;

final class TokenBucketRateLimiter implements RateLimiterInterface
{
    private array $serviceConfigs = [];

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Config $config
    ) {
        $this->serviceConfigs = $this->config->get('rate_limiter.services', []);
    }

    public function canExecute(string $key, int $tokens = 1): bool
    {
        $bucket = $this->getBucketState($key);
        return $bucket['tokens'] >= $tokens;
    }

    public function consumeTokens(string $key, int $tokens = 1): bool
    {
        $cacheKey = "rl_bucket:" . $key;
        $maxAttempts = 10;
        $attempts = 0;

        do {
            $casData = $this->cache->gets($cacheKey);
            if (!$casData) {
                $bucket = $this->createInitialBucket($key);
                if (!$this->cache->add($cacheKey, $bucket, 3600)) {
                    $attempts++;
                    continue;
                }
                $casData = $this->cache->gets($cacheKey);
            }

            $bucket = $casData['value'] ?? [];
            $bucket = $this->refillTokens($bucket, $key);

            if ($bucket['tokens'] < $tokens) {
                return false;
            }

            $bucket['tokens'] -= $tokens;
            $bucket['last_update'] = microtime(true);
            $bucket['requests_in_window']++;

            $success = $this->cache->cas($cacheKey, $bucket, $casData['cas_token'], 3600);

            if ($success) {
                return true;
            }

            $attempts++;
        } while ($attempts < $maxAttempts);

        return false;
    }

    public function getRemainingTokens(string $key): int
    {
        $bucket = $this->getBucketState($key);
        return max(0, (int) $bucket['tokens']);
    }

    public function reset(string $key): void
    {
        $cacheKey = "rl_bucket:" . $key;
        $bucket = $this->createInitialBucket($key);
        $this->cache->set($cacheKey, $bucket, 3600);
    }

    private function getBucketState(string $key): array
    {
        $cacheKey = "rl_bucket:" . $key;
        $bucket = $this->cache->get($cacheKey);

        if ($bucket === null) {
            $bucket = $this->createInitialBucket($key);
        }

        return $this->refillTokens($bucket, $key);
    }

    private function createInitialBucket(string $key): array
    {
        $config = $this->getServiceConfig($key);
        $now = microtime(true);

        return [
            'tokens' => $config['capacity'] ?? 100,
            'capacity' => $config['capacity'] ?? 100,
            'last_refill' => $now,
            'last_update' => $now,
            'refill_rate' => $config['refill_rate'] ?? 10,
            'requests_in_window' => 0,
            'window_start' => $now,
        ];
    }

    private function refillTokens(array $bucket, string $key): array
    {
        $config = $this->getServiceConfig($key);
        $now = microtime(true);
        $elapsed = $now - $bucket['last_refill'];

        if ($elapsed > 0) {
            $refillRate = $config['refill_rate'] ?? 10;
            $tokensToAdd = $elapsed * $refillRate;

            $bucket['tokens'] = min(
                $bucket['capacity'],
                $bucket['tokens'] + $tokensToAdd
            );

            $bucket['last_refill'] = $now;

            $windowDuration = $config['window'] ?? 60;
            if ($now - $bucket['window_start'] >= $windowDuration) {
                $bucket['requests_in_window'] = 0;
                $bucket['window_start'] = $now;
            }
        }

        return $bucket;
    }

    private function getServiceConfig(string $key): array
    {
        if (!isset($this->serviceConfigs[$key])) {
            return [
                'capacity' => 100,
                'refill_rate' => 10,
                'window' => 60,
            ];
        }

        return $this->serviceConfigs[$key]['rate_limiter'] ?? [];
    }
}
