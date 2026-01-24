<?php

declare(strict_types=1);

namespace App\Service\Metrics;

use App\Service\Config\Config;
use DI\Attribute\Injectable;
use Swoole\Atomic;

#[Injectable]
final class MetricsCollector
{
    private array $config;
    private array $counters = [];
    private array $timings = [];
    private array $memoryStats = [];
    private Atomic $requestCount;
    private Atomic $errorCount;

    public function __construct(
        private readonly Config $configuration
    ) {
        $this->config = $this->configuration->get('metrics', []);
        $this->requestCount = new Atomic(0);
        $this->errorCount = new Atomic(0);
    }

    public function incrementHttpRequests(string $method, string $path, int $statusCode): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->requestCount->add(1);

        if ($statusCode >= 400) {
            $this->errorCount->add(1);
        }

        $key = "{$method}:{$path}:{$statusCode}";
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    public function observeHttpDuration(float $duration, string $method, string $path): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $key = "{$method}:{$path}";
        if (!isset($this->timings[$key])) {
            $this->timings[$key] = ['count' => 0, 'total' => 0, 'min' => PHP_FLOAT_MAX, 'max' => 0];
        }

        $this->timings[$key]['count']++;
        $this->timings[$key]['total'] += $duration;
        $this->timings[$key]['min'] = min($this->timings[$key]['min'], $duration);
        $this->timings[$key]['max'] = max($this->timings[$key]['max'], $duration);
    }

    public function observeMemoryUsage(int $bytes): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!isset($this->memoryStats['count'])) {
            $this->memoryStats = ['count' => 0, 'total' => 0, 'max' => 0];
        }

        $this->memoryStats['count']++;
        $this->memoryStats['total'] += $bytes;
        $this->memoryStats['max'] = max($this->memoryStats['max'], $bytes);
    }

    public function getStats(): array
    {
        $stats = [
            'total_requests' => $this->requestCount->get(),
            'total_errors' => $this->errorCount->get(),
            'counters' => $this->counters,
            'timings' => [],
            'memory' => [
                'count' => $this->memoryStats['count'] ?? 0,
                'avg' => isset($this->memoryStats['count']) && $this->memoryStats['count'] > 0
                    ? round($this->memoryStats['total'] / $this->memoryStats['count'])
                    : 0,
                'max' => $this->memoryStats['max'] ?? 0,
            ],
        ];

        foreach ($this->timings as $key => $timing) {
            $stats['timings'][$key] = [
                'count' => $timing['count'],
                'avg' => $timing['count'] > 0 ? round($timing['total'] / $timing['count'], 4) : 0,
                'min' => $timing['min'] === PHP_FLOAT_MAX ? 0 : round($timing['min'], 4),
                'max' => round($timing['max'], 4),
            ];
        }

        return $stats;
    }

    public function reset(): void
    {
        $this->requestCount->set(0);
        $this->errorCount->set(0);
        $this->counters = [];
        $this->timings = [];
        $this->memoryStats = [];
    }

    private function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }
}
