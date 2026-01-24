<?php

declare(strict_types=1);

namespace App\Service\Swoole\EventHandler;

use App\Component\ExchangeRate\ExchangeRateService;
use App\Service\Attribute\SwooleEventHandler;
use App\Service\Config\Config;
use App\Service\Swoole\Contract\SwooleEventHandlerInterface;
use App\Service\Task\TaskRecoveryService;
use DI\Attribute\Injectable;
use Psr\Container\ContainerInterface;
use Swoole\Http\Server;
use Swoole\Timer;

#[Injectable]
#[SwooleEventHandler('WorkerStart')]
class WorkerStartEventHandler implements SwooleEventHandlerInterface
{
    public function __construct(
        private readonly ContainerInterface $container
    ) {
    }

    public function __invoke(mixed ...$args): void
    {
        [$server, $workerId] = $args;

        $isTaskWorker = $workerId >= $server->setting['worker_num'];
        $workerType = $isTaskWorker ? 'Task' : 'Worker';

        echo "{$workerType} #{$workerId} started (PID: " . \posix_getpid() . ")\n";

        if (!$isTaskWorker && $workerId === 0) {
            $this->recoverTasks();
            $this->startExchangeRateTimer();
        }
    }

    private function startExchangeRateTimer(): void
    {
        $config = $this->container->get(Config::class);
        $interval = (int) $config->get('exchangerate.update_interval', 3600) * 1000;

        $this->updateExchangeRates();

        Timer::tick($interval, fn () => $this->updateExchangeRates());

        echo "Exchange rate timer started (interval: " . ($interval / 1000) . "s)\n";
    }

    private function updateExchangeRates(): void
    {
        try {
            $service = $this->container->get(ExchangeRateService::class);
            $result = $service->updateAllRates();
            $total = count($result['fiat'] ?? []) + count($result['crypto'] ?? []);
            echo "[" . date('Y-m-d H:i:s') . "] Exchange rates updated: {$total} currencies\n";
        } catch (\Throwable $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Exchange rate update failed: {$e->getMessage()}\n";
        }
    }

    private function recoverTasks(): void
    {
        try {
            $recoveryService = $this->container->get(TaskRecoveryService::class);
            $recoveryService->recoverOnWorkerStart(0);
        } catch (\Throwable $e) {
            echo "Task recovery failed: {$e->getMessage()}\n";
        }
    }
}
