<?php

declare(strict_types=1);

namespace App\Service\Swoole\EventHandler;

use App\Component\ExchangeRate\ExchangeRateService;
use App\Service\Attribute\SwooleEventHandler;
use App\Service\Config\Config;
use App\Service\Logging\LoggerFactory;
use App\Service\Swoole\Contract\SwooleEventHandlerInterface;
use App\Service\Task\TaskRecoveryService;
use DI\Attribute\Injectable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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

        $this->getLogger()->info("{$workerType} started", [
            'worker_id' => $workerId,
            'pid' => \posix_getpid(),
            'type' => $workerType,
        ]);

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

        $this->getLogger()->info('Exchange rate timer started', [
            'interval_sec' => $interval / 1000,
        ]);
    }

    private function updateExchangeRates(): void
    {
        try {
            $service = $this->container->get(ExchangeRateService::class);
            $result = $service->updateAllRates();
            $total = count($result['fiat'] ?? []) + count($result['crypto'] ?? []);
            $this->getLogger()->info('Exchange rates updated', ['total' => $total]);
        } catch (\Throwable $e) {
            $this->getLogger()->error('Exchange rate update failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recoverTasks(): void
    {
        try {
            $recoveryService = $this->container->get(TaskRecoveryService::class);
            $recoveryService->recoverOnWorkerStart(0);
        } catch (\Throwable $e) {
            $this->getLogger()->error('Task recovery failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getLogger(): LoggerInterface
    {
        return $this->container->get(LoggerFactory::class)->create();
    }
}
