<?php

declare(strict_types=1);

namespace App\Application\Meals\Http\Handler;

use App\Application\Meals\Task\MealsWebhookProcessTask;
use App\Component\Telegram\WebhookTokenValidator;
use App\Service\Attribute\Route;
use App\Service\Cache\CacheInterface;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;
use App\Service\Task\TaskManager;

#[Route('/telegram/meal/{token}', 'POST')]
final class WebhookHandler
{
    private const int DEDUP_TTL = 86400;

    public function __construct(
        private TaskManager $taskManager,
        private WebhookTokenValidator $tokenValidator,
        private CacheInterface $cache
    ) {
    }

    public function handle(Request $request, Response $response, array $vars): void
    {
        $token = (string) ($vars['token'] ?? '');
        if (!$this->tokenValidator->validate('telegram.meals_bot_token', $token)) {
            $response->json(['ok' => false], 403);
            return;
        }

        $response->json(['ok' => true]);

        $data = $request->getBody();
        if (empty($data)) {
            return;
        }

        $updateId = $data['update_id'] ?? null;
        if ($updateId !== null && $this->isDuplicate((int) $updateId)) {
            return;
        }

        $this->taskManager->dispatch(
            MealsWebhookProcessTask::class,
            ['update' => $data],
            $updateId,
            'meals_update'
        );
    }

    private function isDuplicate(int $updateId): bool
    {
        $key = "meals:update:{$updateId}";

        if ($this->cache->add($key, 1, self::DEDUP_TTL)) {
            return false;
        }

        return $this->cache->exists($key);
    }
}
