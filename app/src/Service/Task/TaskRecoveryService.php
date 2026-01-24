<?php

declare(strict_types=1);

namespace App\Service\Task;

use DI\Attribute\Injectable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[Injectable]
class TaskRecoveryService
{
    private bool $recoveryPerformed = false;

    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly TaskManager $taskManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
    }

    public function recoverOnWorkerStart(int $workerId): void
    {
        if ($workerId !== 0) {
            return;
        }

        if ($this->recoveryPerformed) {
            return;
        }

        $this->recoveryPerformed = true;

        $this->logger->info('[TaskRecovery] Starting task recovery on worker start');

        $this->recoverInterruptedTasks();

        $this->logger->info('[TaskRecovery] Task recovery completed');
    }

    private function recoverInterruptedTasks(): void
    {
        $runningTasks = $this->taskRepository->findRunningOnStartup();

        if (empty($runningTasks)) {
            $this->logger->info('[TaskRecovery] No interrupted tasks to recover');
            return;
        }

        $this->logger->info('[TaskRecovery] Found interrupted tasks to recover', [
            'count' => count($runningTasks),
        ]);

        foreach ($runningTasks as $task) {
            $this->recoverTask($task);
        }
    }

    private function recoverTask(array $task): void
    {
        $taskId = $task['id'];
        $taskType = $task['type'];
        $retryCount = $task['retry_count'] ?? 0;
        $maxRetries = $task['max_retries'] ?? 3;

        $this->logger->info('[TaskRecovery] Recovering task', [
            'task_id' => $taskId,
            'type' => $taskType,
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'progress' => $task['progress'] ?? [],
        ]);

        if ($retryCount >= $maxRetries) {
            $this->taskRepository->markFailed($taskId, 'Task interrupted and max retries exceeded');
            $this->logger->warning('[TaskRecovery] Task exceeded max retries, marking as failed', [
                'task_id' => $taskId,
            ]);
            return;
        }

        $this->taskManager->retry($taskId);
    }

    public function cleanupStaleTasks(int $hoursOld = 24): int
    {
        $this->logger->info('[TaskRecovery] Cleaning up stale tasks');

        $staleTasks = $this->taskRepository->findByStatus('running');
        $cleaned = 0;

        foreach ($staleTasks as $task) {
            $startedAt = $task['started_at'] ?? $task['created_at'];
            if ($startedAt === null) {
                continue;
            }

            $startedTimestamp = strtotime($startedAt);
            $threshold = time() - ($hoursOld * 3600);

            if ($startedTimestamp < $threshold) {
                $this->taskRepository->markFailed($task['id'], "Task stale - running for more than {$hoursOld} hours");
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->logger->info('[TaskRecovery] Cleaned up stale tasks', ['count' => $cleaned]);
        }

        return $cleaned;
    }
}
