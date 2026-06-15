<?php

declare(strict_types=1);

namespace App\Application\Budget\Http\Handler;

use App\Application\Budget\Task\WebhookProcessTask;
use App\Component\Telegram\WebhookTokenValidator;
use App\Service\Attribute\Route;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;
use App\Service\Task\TaskManager;

#[Route('/telegram/{token}', 'POST')]
#[Route('/telegram/budget/{token}', 'POST')]
final class WebhookHandler
{
    public function __construct(
        private TaskManager $taskManager,
        private WebhookTokenValidator $tokenValidator
    ) {
    }

    public function handle(Request $request, Response $response, array $vars): void
    {
        $token = (string) ($vars['token'] ?? '');
        if (!$this->tokenValidator->validate('telegram.budget_bot_token', $token)) {
            $response->json(['ok' => false], 403);
            return;
        }

        $response->json(['ok' => true]);

        $data = $request->getBody();
        if (empty($data)) {
            return;
        }

        $updateId = $data['update_id'] ?? null;
        $this->taskManager->dispatch(
            WebhookProcessTask::class,
            ['update' => $data],
            $updateId,
            'telegram_update'
        );
    }
}
