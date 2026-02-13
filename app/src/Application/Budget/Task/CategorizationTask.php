<?php

declare(strict_types=1);

namespace App\Application\Budget\Task;

use App\Application\Budget\Service\StatsService;
use App\Service\Swoole\Task\Handler\AbstractTask;

final class CategorizationTask extends AbstractTask
{
    protected int $maxRetries = 1;

    public function handle(): mixed
    {
        $chatId = (int) ($this->payload['chat_id'] ?? 0);
        $months = (int) ($this->payload['months'] ?? 3);
        $currency = (string) ($this->payload['currency'] ?? 'THB');

        if ($chatId === 0) {
            return ['status' => 'error', 'message' => 'Missing chat_id'];
        }

        $statsService = $this->getService(StatsService::class);
        $result = $statsService->getStats($chatId, $months, $currency);

        $this->getLogger()->info('[CategorizationTask] Completed', [
            'chat_id' => $chatId,
            'months' => $months,
            'currency' => $currency,
        ]);

        return [
            'status' => 'ok',
            'chat_id' => $chatId,
            'stats' => $result,
        ];
    }
}
