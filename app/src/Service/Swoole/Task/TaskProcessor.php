<?php

declare(strict_types=1);

namespace App\Service\Swoole\Task;

use App\Service\Swoole\Task\Contract\TaskInterface;
use App\Service\Swoole\Task\Exception\TaskException;
use App\Service\Task\AbstractPersistentTask;
use App\Service\Task\TaskRepository;
use DI\Attribute\Injectable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[Injectable]
class TaskProcessor
{
    public function __construct(
        private LoggerInterface $logger,
        private ContainerInterface $container
    ) {
    }

    public function process(array $taskData): mixed
    {
        $taskType = $taskData['type'] ?? null;
        $payload = $taskData['payload'] ?? [];
        $taskDbId = $taskData['task_db_id'] ?? null;

        if (!$taskType) {
            throw new TaskException('Task type is required');
        }

        if (!class_exists($taskType)) {
            throw new TaskException("Task class not found: {$taskType}");
        }

        $taskRepository = null;
        if ($taskDbId !== null) {
            $taskRepository = $this->container->get(TaskRepository::class);
            $taskRepository->markRunning($taskDbId);
        }

        try {
            $task = $taskType::fromPayload($payload);
            if (!$task instanceof TaskInterface) {
                throw new TaskException("Task must implement TaskInterface: {$taskType}");
            }

            if (method_exists($task, 'setContainer')) {
                $task->setContainer($this->container);
            }

            if ($task instanceof AbstractPersistentTask && $taskDbId !== null && $taskRepository !== null) {
                $task->setTaskId($taskDbId);
                $task->setTaskRepository($taskRepository);
            }

            $this->logger->info('Processing task', [
                'type' => $taskType,
                'task_db_id' => $taskDbId,
            ]);

            $result = $task->handle();
            $nextClasses = method_exists($task, 'getNext') ? $task->getNext() : [];

            if (!is_array($result)) {
                $result = ['data' => $result];
            }

            if ($taskDbId !== null && $taskRepository !== null) {
                $taskRepository->markCompleted($taskDbId, $result);
            }

            $next = [];
            foreach ($nextClasses as $nextClass) {
                $next[] = [
                    'type' => $nextClass,
                    'payload' => $result,
                ];
            }

            $this->logger->info('[TaskProcessor] Task completed', [
                'type' => $taskType,
                'task_db_id' => $taskDbId,
                'next_count' => count($next),
            ]);

            $result['next'] = $next;

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Task failed', [
                'type' => $taskType,
                'task_db_id' => $taskDbId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            if ($taskDbId !== null && $taskRepository !== null) {
                $this->handleTaskError($taskDbId, $taskRepository, $taskData, $e);
            }

            throw $e;
        }
    }

    private function handleTaskError(int $taskDbId, TaskRepository $taskRepository, array $taskData, \Throwable $e): void
    {
        $task = $taskRepository->findById($taskDbId);
        if (!$task) {
            return;
        }

        $retryCount = $task['retry_count'] ?? 0;
        $maxRetries = $task['max_retries'] ?? 3;

        if ($retryCount < $maxRetries) {
            $taskRepository->incrementRetryCount($taskDbId);
            $taskRepository->markRetrying($taskDbId);
        } else {
            $taskRepository->markFailed($taskDbId, $e->getMessage());
        }
    }
}
