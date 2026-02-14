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
        $topicId = isset($this->payload['topic_id']) ? (int) $this->payload['topic_id'] : null;

        $this->getLogger()->info('[CategorizationTask] START', [
            'chat_id' => $chatId,
            'months' => $months,
            'currency' => $currency,
            'topic_id' => $topicId,
            'payload' => $this->payload,
        ]);

        if ($chatId === 0) {
            $this->getLogger()->error('[CategorizationTask] Missing chat_id in payload');
            return ['status' => 'error', 'message' => 'Missing chat_id'];
        }

        try {
            $statsService = $this->getService(StatsService::class);
            $this->getLogger()->info('[CategorizationTask] StatsService resolved, calling getStats');

            $result = $statsService->getStats($chatId, $months, $currency, false, $topicId);

            $this->getLogger()->info('[CategorizationTask] Completed', [
                'chat_id' => $chatId,
                'has_text' => !empty($result['text']),
                'tokens' => $result['tokens'] ?? 0,
                'text_length' => strlen($result['text'] ?? ''),
            ]);

            return [
                'status' => 'ok',
                'chat_id' => $chatId,
                'stats' => $result,
            ];
        } catch (\Throwable $e) {
            $this->getLogger()->error('[CategorizationTask] FAILED', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
