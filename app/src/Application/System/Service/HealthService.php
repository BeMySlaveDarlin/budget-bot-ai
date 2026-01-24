<?php

declare(strict_types=1);

namespace App\Application\System\Service;

use App\Application\System\HealthCheck\Contract\HealthCheckInterface;
use DI\Attribute\Injectable;

#[Injectable]
class HealthService
{
    private array $healthChecks = [];

    public function registerCheck(HealthCheckInterface $check): void
    {
        $this->healthChecks[$check->getName()] = $check;
    }

    public function runAll(): array
    {
        $checks = [];
        foreach ($this->healthChecks as $check) {
            $checks[$check->getName()] = $check->check();
        }

        return $checks;
    }

    public function run(string $name): ?array
    {
        if (!isset($this->healthChecks[$name])) {
            return null;
        }

        return $this->healthChecks[$name]->check();
    }

    public function isAllHealthy(array $checks): bool
    {
        return !in_array(false, array_column($checks, 'healthy'), true);
    }

    public function getRegisteredChecks(): array
    {
        return array_keys($this->healthChecks);
    }
}
