<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Service\Swoole\Task\Contract\TaskInterface;
use DI\Attribute\Injectable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Http\Server;

#[Injectable]
class TaskManager
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly Server $server
    ) {
    }

    public function dispatch(string $taskClass, array $payload, ?int $contextId = null, ?string $contextType = null): int
    {
        $task = $this->createTaskInstance($taskClass, $payload);
        $maxRetries = $task->getMaxRetries();

        $taskId = $this->taskRepository->create($taskClass, $payload, $contextId, $contextType, $maxRetries);

        $taskData = [
            'task_db_id' => $taskId,
            'type' => $taskClass,
            'payload' => $payload,
            'max_retries' => $maxRetries,
            'created_at' => time(),
        ];

        $swooleTaskId = $this->server->task($taskData);

        if ($swooleTaskId !== false) {
            $this->logger->info('[TaskManager] Task dispatched', [
                'task_db_id' => $taskId,
                'swoole_task_id' => $swooleTaskId,
                'type' => $taskClass,
                'context_type' => $contextType,
                'context_id' => $contextId,
            ]);
        } else {
            $this->taskRepository->markFailed($taskId, 'Failed to dispatch to Swoole');
            $this->logger->error('[TaskManager] Failed to dispatch task', [
                'task_db_id' => $taskId,
                'type' => $taskClass,
            ]);
        }

        return $taskId;
    }

    public function dispatchNext(array $nextTasks, array $previousResult): void
    {
        foreach ($nextTasks as $taskClass) {
            $payload = array_merge($previousResult, [
                'previous_task_result' => $previousResult,
            ]);

            $contextId = $previousResult['chat_id'] ?? null;
            $contextType = $contextId ? 'chat' : null;

            $this->dispatch($taskClass, $payload, $contextId, $contextType);
        }
    }

    public function execute(int $taskDbId, string $taskClass, array $payload): array
    {
        $this->taskRepository->markRunning($taskDbId);

        try {
            $task = $this->createTaskInstance($taskClass, $payload);

            if ($task instanceof AbstractPersistentTask) {
                $task->setTaskId($taskDbId);
                $task->setTaskRepository($this->taskRepository);
            }

            $result = $task->handle();

            $this->taskRepository->markCompleted($taskDbId, is_array($result) ? $result : ['result' => $result]);

            $nextTasks = $task->getNext();
            if (!empty($nextTasks) && is_array($result)) {
                $this->dispatchNext($nextTasks, $result);
            }

            return is_array($result) ? $result : ['result' => $result];

        } catch (\Throwable $e) {
            return $this->handleTaskError($taskDbId, $taskClass, $payload, $e);
        }
    }

    public function retry(int $taskDbId): bool
    {
        $task = $this->taskRepository->findById($taskDbId);
        if (!$task) {
            return false;
        }

        $retryCount = $this->taskRepository->incrementRetryCount($taskDbId);

        if ($retryCount >= $task['max_retries']) {
            $this->taskRepository->markFailed($taskDbId, "Max retries ({$task['max_retries']}) exceeded");
            return false;
        }

        $this->taskRepository->markRetrying($taskDbId);

        $taskData = [
            'task_db_id' => $taskDbId,
            'type' => $task['type'],
            'payload' => $task['payload'],
            'max_retries' => $task['max_retries'],
            'retry_count' => $retryCount,
            'created_at' => time(),
        ];

        $swooleTaskId = $this->server->task($taskData);

        return $swooleTaskId !== false;
    }

    public function cancel(int $taskDbId): bool
    {
        $task = $this->taskRepository->findById($taskDbId);
        if (!$task || !in_array($task['status'], ['pending', 'retrying'])) {
            return false;
        }

        $this->taskRepository->updateStatus($taskDbId, 'cancelled');
        return true;
    }

    public function getStatus(int $taskDbId): ?array
    {
        return $this->taskRepository->findById($taskDbId);
    }

    private function handleTaskError(int $taskDbId, string $taskClass, array $payload, \Throwable $e): array
    {
        $task = $this->taskRepository->findById($taskDbId);
        $retryCount = $task['retry_count'] ?? 0;
        $maxRetries = $task['max_retries'] ?? 3;

        $this->logger->error('[TaskManager] Task execution failed', [
            'task_db_id' => $taskDbId,
            'type' => $taskClass,
            'error' => $e->getMessage(),
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
        ]);

        if ($retryCount < $maxRetries) {
            $this->retry($taskDbId);
            return ['status' => 'retrying', 'error' => $e->getMessage()];
        }

        $this->taskRepository->markFailed($taskDbId, $e->getMessage());
        return ['status' => 'failed', 'error' => $e->getMessage()];
    }

    private function createTaskInstance(string $taskClass, array $payload): TaskInterface
    {
        if (!class_exists($taskClass)) {
            throw new \InvalidArgumentException("Task class {$taskClass} does not exist");
        }

        $task = $taskClass::fromPayload($payload);
        $task->setContainer($this->container);

        return $task;
    }
}
