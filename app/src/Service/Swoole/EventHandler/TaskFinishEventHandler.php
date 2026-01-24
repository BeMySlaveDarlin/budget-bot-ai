<?php

declare(strict_types=1);

namespace App\Service\Swoole\EventHandler;

use App\Service\Attribute\SwooleEventHandler;
use App\Service\Swoole\Contract\SwooleEventHandlerInterface;
use App\Service\Task\TaskManager;
use DI\Attribute\Injectable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Server;

#[Injectable]
#[SwooleEventHandler('Finish')]
class TaskFinishEventHandler implements SwooleEventHandlerInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ContainerInterface $container
    ) {
    }

    public function __invoke(mixed ...$args): void
    {
        [$server, $taskId, $taskResult] = $args;
        $this->handle($server, $taskId, $taskResult);
    }

    public function handle(Server $server, int $taskId, mixed $taskResult): void
    {

        $status = $taskResult['status'] ?? 'unknown';
        $taskType = $taskResult['task_type'] ?? 'unknown';

        $this->logger->info('[TaskFinish] Task completed', [
            'task_id' => $taskId,
            'status' => $status,
            'task_type' => $taskType,
        ]);

        if ($status === 'failed' || $status === 'retrying') {
            $this->logger->error('[TaskFinish] Task failed/retrying, skipping chain', [
                'task_id' => $taskId,
                'status' => $status,
                'error' => $taskResult['error'] ?? 'unknown error',
            ]);

            return;
        }

        $this->dispatchNextTasks($taskResult);
    }

    private function dispatchNextTasks(array $taskResult): void
    {
        $nextTasks = $taskResult['next'] ?? [];
        if (empty($nextTasks)) {
            $this->logger->info('[TaskFinish] No next tasks to dispatch');

            return;
        }

        $this->logger->info('[TaskFinish] Dispatching next tasks', [
            'count' => count($nextTasks),
        ]);

        $taskManager = $this->container->get(TaskManager::class);

        foreach ($nextTasks as $nextTask) {
            $taskType = $nextTask['type'] ?? null;
            if (!$taskType) {
                $this->logger->error('[TaskFinish] Next task missing type', [
                    'task' => $nextTask,
                ]);
                continue;
            }

            $payload = $nextTask['payload'] ?? [];

            $contextId = $payload['chat_id'] ?? null;
            $contextType = $contextId ? 'chat' : null;

            $taskDbId = $taskManager->dispatch($taskType, $payload, $contextId, $contextType);

            $this->logger->info('[TaskFinish] Next task dispatched (persistent)', [
                'task_db_id' => $taskDbId,
                'type' => $taskType,
            ]);
        }
    }

    public function getEventName(): string
    {
        return 'finish';
    }
}
