<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Budget\Command\Attribute\BotCommand;
use App\Application\Budget\Command\Contract\BotCommandInterface;
use App\Application\Budget\DTO\CommandContext;
use App\Application\Budget\Task\CategorizationTask;
use App\Component\Telegram\Repository\ChatRepository;
use App\Service\Config\Config;
use App\Service\Task\TaskManager;
use DI\Attribute\Injectable;

#[Injectable]
#[BotCommand(command: 'view', description: 'Открыть отчёты')]
final class ViewCommand implements BotCommandInterface
{
    public function __construct(
        private Config $config,
        private TaskManager $taskManager,
        private ChatRepository $chatRepo
    ) {
    }

    public function execute(CommandContext $ctx): ?string
    {
        $appUrl = $this->config->get('app.url', '');
        if ($appUrl === '') {
            return '❌ URL приложения не настроен';
        }

        $chatId = $ctx->getChatId();
        $topicId = $ctx->getTopicId();
        $currency = $ctx->getCurrency();
        $months = $this->chatRepo->getPlanningPeriod($chatId);

        $payload = [
            'chat_id' => $chatId,
            'months' => $months,
            'currency' => $currency,
        ];

        if ($topicId !== null) {
            $payload['topic_id'] = $topicId;
        }

        $this->taskManager->dispatch(
            CategorizationTask::class,
            $payload,
            $chatId,
            'chat'
        );

        return '📊 Открой отчёт по кнопке ниже';
    }

    public function getKeyboard(CommandContext $ctx): ?array
    {
        $appUrl = $this->config->get('app.url', '');
        if ($appUrl === '') {
            return null;
        }

        $chatId = $ctx->getChatId();
        $topicId = $ctx->getTopicId();
        $isPrivate = ($ctx->chat['type'] ?? '') === 'private';

        $topicParam = $topicId !== null ? "&topic_id={$topicId}" : '';

        if ($isPrivate) {
            $url = "{$appUrl}/report?chat_id={$chatId}{$topicParam}";
            $button = ['text' => '📊 Открыть отчёт', 'web_app' => ['url' => $url]];
        } else {
            $ts = time();
            $sig = hash_hmac('sha256', "{$chatId}:{$ts}", $this->config->get('telegram.bot_token', ''));
            $url = "{$appUrl}/report?chat_id={$chatId}&ts={$ts}&sig={$sig}{$topicParam}";
            $button = ['text' => '📊 Открыть отчёт', 'url' => $url];
        }

        return ['inline_keyboard' => [[$button]]];
    }
}
