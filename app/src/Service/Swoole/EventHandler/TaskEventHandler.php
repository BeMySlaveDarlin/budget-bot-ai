<?php

declare(strict_types=1);

namespace App\Service\Swoole\EventHandler;

use App\Service\Attribute\SwooleEventHandler;
use App\Service\Swoole\Contract\SwooleEventHandlerInterface;
use App\Service\Swoole\Task\TaskProcessor;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;
use Swoole\Server;
use Swoole\Server\Task;

#[Injectable]
#[SwooleEventHandler('Task')]
class TaskEventHandler implements SwooleEventHandlerInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private TaskProcessor $taskProcessor
    ) {
    }

    public function __invoke(mixed ...$args): void
    {
        $task = $args[1];
        $taskData = $task->data;
        $taskId = $task->id;

        $this->logger->info('Processing Swoole task', [
            'task_id' => $taskId,
            'src_worker_id' => $task->worker_id,
            'task_type' => $taskData['type'] ?? 'unknown',
        ]);

        try {
            $result = $this->taskProcessor->process($taskData);
            $task->finish($result);
        } catch (\Throwable $e) {
            $this->logger->error('Task processing failed', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $task->finish([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'task_id' => $taskId,
            ]);
        }
    }

    public function getEventName(): string
    {
        return 'task';
    }
}
