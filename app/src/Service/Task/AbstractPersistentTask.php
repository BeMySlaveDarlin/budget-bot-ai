<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Service\Swoole\Task\Handler\AbstractTask;

abstract class AbstractPersistentTask extends AbstractTask
{
    protected ?int $taskId = null;
    protected ?TaskRepository $taskRepository = null;

    public function setTaskId(int $taskId): void
    {
        $this->taskId = $taskId;
    }

    public function getTaskId(): ?int
    {
        return $this->taskId;
    }

    public function setTaskRepository(TaskRepository $taskRepository): void
    {
        $this->taskRepository = $taskRepository;
    }

    protected function getProgress(): array
    {
        if ($this->taskId === null || $this->taskRepository === null) {
            return [];
        }

        return $this->taskRepository->getProgress($this->taskId);
    }

    protected function saveProgress(array $progress): void
    {
        if ($this->taskId === null || $this->taskRepository === null) {
            return;
        }

        $this->taskRepository->saveProgress($this->taskId, $progress);
    }

    protected function isStepCompleted(string $stepName): bool
    {
        $progress = $this->getProgress();
        return isset($progress[$stepName]) && $progress[$stepName] === true;
    }

    protected function markStepCompleted(string $stepName, mixed $result = true): void
    {
        $this->saveProgress([$stepName => $result]);
    }

    protected function getStepResult(string $stepName): mixed
    {
        $progress = $this->getProgress();
        return $progress[$stepName] ?? null;
    }

    protected function executeStep(string $stepName, callable $action): mixed
    {
        if ($this->isStepCompleted($stepName)) {
            $this->getLogger()->debug("Step '{$stepName}' already completed, skipping", [
                'task_id' => $this->taskId,
            ]);
            return $this->getStepResult($stepName);
        }

        $this->getLogger()->info("Executing step '{$stepName}'", [
            'task_id' => $this->taskId,
        ]);

        $result = $action();

        $this->markStepCompleted($stepName, $result === null ? true : $result);

        return $result;
    }

    protected function saveIntermediateResult(array $result): void
    {
        if ($this->taskId === null || $this->taskRepository === null) {
            return;
        }

        $this->taskRepository->saveResult($this->taskId, $result);
    }
}
