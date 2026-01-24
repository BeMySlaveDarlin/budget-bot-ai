<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class TaskRepository
{
    public function __construct(
        private readonly DatabaseConnection $db
    ) {
    }

    public function create(string $type, array $payload, ?int $contextId = null, ?string $contextType = null, int $maxRetries = 3): int
    {
        $sql = "
            INSERT INTO tasks (type, status, payload, progress, max_retries, context_id, context_type)
            VALUES (:type, :status, :payload, :progress, :max_retries, :context_id, :context_type)
        ";

        return $this->db->insert($sql, [
            'type' => $type,
            'status' => 'pending',
            'payload' => json_encode($payload),
            'progress' => json_encode([]),
            'max_retries' => $maxRetries,
            'context_id' => $contextId,
            'context_type' => $contextType,
        ]);
    }

    public function findById(int $id): ?array
    {
        $task = $this->db->queryFirst('SELECT * FROM tasks WHERE id = :id', ['id' => $id]);
        return $task ? $this->hydrateTask($task) : null;
    }

    public function findByStatus(string $status, ?string $type = null, int $limit = 100): array
    {
        $sql = 'SELECT * FROM tasks WHERE status = :status';
        $params = ['status' => $status];

        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }

        $sql .= ' ORDER BY created_at ASC LIMIT :limit';
        $params['limit'] = $limit;

        $tasks = $this->db->query($sql, $params);
        return array_map(fn($t) => $this->hydrateTask($t), $tasks);
    }

    public function findRunningOnStartup(): array
    {
        $tasks = $this->db->query(
            "SELECT * FROM tasks WHERE status IN ('running', 'retrying') ORDER BY created_at ASC"
        );
        return array_map(fn($t) => $this->hydrateTask($t), $tasks);
    }

    public function updateStatus(int $id, string $status, ?string $errorMessage = null): void
    {
        if ($errorMessage !== null) {
            $this->db->execute(
                'UPDATE tasks SET status = :status, error_message = :error_message WHERE id = :id',
                ['status' => $status, 'error_message' => $errorMessage, 'id' => $id]
            );
        } else {
            $this->db->execute(
                'UPDATE tasks SET status = :status WHERE id = :id',
                ['status' => $status, 'id' => $id]
            );
        }
    }

    public function markRunning(int $id): void
    {
        $this->db->execute(
            'UPDATE tasks SET status = :status, started_at = :started_at WHERE id = :id',
            ['status' => 'running', 'started_at' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    public function markCompleted(int $id, array $result): void
    {
        $this->db->execute(
            'UPDATE tasks SET status = :status, result = :result, completed_at = :completed_at WHERE id = :id',
            ['status' => 'completed', 'result' => json_encode($result), 'completed_at' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    public function markFailed(int $id, string $errorMessage): void
    {
        $this->db->execute(
            'UPDATE tasks SET status = :status, error_message = :error_message, completed_at = :completed_at WHERE id = :id',
            ['status' => 'failed', 'error_message' => $errorMessage, 'completed_at' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    public function markRetrying(int $id): void
    {
        $this->db->execute(
            'UPDATE tasks SET status = :status WHERE id = :id',
            ['status' => 'retrying', 'id' => $id]
        );
    }

    public function incrementRetryCount(int $id): int
    {
        $this->db->execute(
            'UPDATE tasks SET retry_count = retry_count + 1 WHERE id = :id',
            ['id' => $id]
        );
        $task = $this->findById($id);
        return $task['retry_count'] ?? 0;
    }

    public function saveProgress(int $id, array $progress): void
    {
        $existing = $this->getProgress($id);
        $merged = array_merge($existing, $progress);

        $this->db->execute(
            'UPDATE tasks SET progress = :progress WHERE id = :id',
            ['progress' => json_encode($merged), 'id' => $id]
        );
    }

    public function getProgress(int $id): array
    {
        $task = $this->db->queryFirst('SELECT progress FROM tasks WHERE id = :id', ['id' => $id]);
        if (!$task || empty($task['progress'])) {
            return [];
        }
        return json_decode($task['progress'], true) ?? [];
    }

    public function saveResult(int $id, array $result): void
    {
        $this->db->execute(
            'UPDATE tasks SET result = :result WHERE id = :id',
            ['result' => json_encode($result), 'id' => $id]
        );
    }

    public function findByContext(string $contextType, int $contextId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM tasks WHERE context_type = :context_type AND context_id = :context_id';
        $params = ['context_type' => $contextType, 'context_id' => $contextId];

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY created_at DESC';
        $tasks = $this->db->query($sql, $params);
        return array_map(fn($t) => $this->hydrateTask($t), $tasks);
    }

    public function cancelPendingByContext(string $contextType, int $contextId): int
    {
        return $this->db->update(
            "UPDATE tasks SET status = 'cancelled' WHERE context_type = :context_type AND context_id = :context_id AND status = 'pending'",
            ['context_type' => $contextType, 'context_id' => $contextId]
        );
    }

    public function cleanupOldTasks(int $daysOld = 30): int
    {
        return $this->db->delete(
            "DELETE FROM tasks WHERE status IN ('completed', 'failed', 'cancelled') AND created_at < NOW() - INTERVAL '1 day' * :days",
            ['days' => $daysOld]
        );
    }

    private function hydrateTask(array $task): array
    {
        if (isset($task['payload']) && is_string($task['payload'])) {
            $task['payload'] = json_decode($task['payload'], true) ?? [];
        }
        if (isset($task['progress']) && is_string($task['progress'])) {
            $task['progress'] = json_decode($task['progress'], true) ?? [];
        }
        if (isset($task['result']) && is_string($task['result'])) {
            $task['result'] = json_decode($task['result'], true);
        }
        return $task;
    }
}
