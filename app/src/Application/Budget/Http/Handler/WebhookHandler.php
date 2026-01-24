<?php

declare(strict_types=1);

namespace App\Application\Budget\Http\Handler;

use App\Application\Budget\Task\WebhookProcessTask;
use App\Service\Attribute\Route;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;
use App\Service\Task\TaskManager;

#[Route('/telegram/{token}', 'POST')]
class WebhookHandler
{
    public function __construct(
        private TaskManager $taskManager
    ) {
    }

    public function handle(Request $request, Response $response, array $vars): void
    {
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
