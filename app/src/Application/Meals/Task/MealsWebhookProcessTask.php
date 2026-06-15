<?php

declare(strict_types=1);

namespace App\Application\Meals\Task;

use App\Application\Meals\Service\MealChatService;
use App\Application\Meals\Service\MealSessionService;
use App\Component\Telegram\Repository\ChatRepository;
use App\Component\Telegram\Repository\ChatUserRepository;
use App\Component\Telegram\Repository\UserRepository;
use App\Component\Telegram\TelegramClient;
use App\Service\Config\Config;
use App\Service\Swoole\Task\Handler\AbstractTask;

class MealsWebhookProcessTask extends AbstractTask
{
    protected int $maxRetries = 2;

    public function handle(): mixed
    {
        $update = $this->payload['update'] ?? [];
        if (empty($update)) {
            return ['status' => 'error', 'message' => 'Empty update'];
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return ['status' => 'ok', 'type' => 'no_message'];
        }

        $text = trim($message['text'] ?? '');
        $chatTg = $message['chat'];
        $fromTg = $message['from'] ?? [];
        $topicId = $message['message_thread_id'] ?? null;

        $user = $this->getService(UserRepository::class)->findOrCreate($fromTg);
        $chat = $this->getService(ChatRepository::class)->findOrCreate($chatTg);
        $isPrivateChat = ($chatTg['type'] ?? '') === 'private';
        $this->getService(ChatUserRepository::class)->ensureExists((int) $chat['id'], (int) $user['id'], $isPrivateChat);

        $this->getLogger()->info('[Meals] message received', [
            'chat_id' => $chat['id'],
            'user_id' => $user['id'],
            'is_command' => str_starts_with($text, '/'),
        ]);

        $reply = $this->route($text, (int) $chat['id'], (int) $user['id'], $topicId, $chatTg);

        if ($reply !== null && $reply !== '') {
            $this->send((int) $chatTg['id'], $reply, $topicId);
        }

        return ['status' => 'ok', 'type' => 'message'];
    }

    private function route(string $text, int $chatId, int $userId, ?int $topicId, array $chatTg): ?string
    {
        if ($text === '') {
            return null;
        }

        $session = $this->getService(MealSessionService::class);

        if (str_starts_with($text, '/start') || str_starts_with($text, '/help')) {
            return $this->helpText();
        }

        if (str_starts_with($text, '/new')) {
            $session->reset($chatId);

            return 'Начал новую сессию — прошлый разговор забыт. Что приготовить?';
        }

        if (str_starts_with($text, '/fridge')) {
            $this->sendFridgeButton($chatId, $topicId, $chatTg);

            return null;
        }

        $sessionId = $session->current($chatId);

        return $this->getService(MealChatService::class)->handle($chatId, $userId, $topicId, $sessionId, $text);
    }

    private function sendFridgeButton(int $internalChatId, ?int $topicId, array $chatTg): void
    {
        $appUrl = $this->getService(Config::class)->get('app.url', '');
        if ($appUrl === '') {
            $this->send((int) $chatTg['id'], '❌ URL приложения не настроен', $topicId);

            return;
        }

        $topicParam = $topicId !== null ? "&topic_id={$topicId}" : '';
        $isPrivate = ($chatTg['type'] ?? '') === 'private';

        if ($isPrivate) {
            $url = "{$appUrl}/meals?chat_id={$internalChatId}{$topicParam}";
            $button = ['text' => '🍽 Открыть холодильник', 'web_app' => ['url' => $url]];
        } else {
            $ts = time();
            $token = $this->getService(Config::class)->get('telegram.meals_bot_token', '');
            $sig = hash_hmac('sha256', "{$internalChatId}:{$ts}", $token);
            $url = "{$appUrl}/meals?chat_id={$internalChatId}&ts={$ts}&sig={$sig}{$topicParam}";
            $button = ['text' => '🍽 Открыть холодильник', 'url' => $url];
        }

        $this->mealsClient()->sendMessageWithKeyboard(
            (int) $chatTg['id'],
            'Управление холодильником и памятью:',
            ['inline_keyboard' => [[$button]]],
            messageThreadId: $topicId
        );
    }

    private function send(int $chatTgId, string $text, ?int $topicId): void
    {
        $telegram = $this->mealsClient();

        foreach ($telegram->splitMessage($text) as $chunk) {
            $telegram->sendMessage($chatTgId, $chunk, messageThreadId: $topicId);
        }
    }

    private function mealsClient(): TelegramClient
    {
        $token = $this->getService(Config::class)->get('telegram.meals_bot_token', '');

        return $this->getService(TelegramClient::class)->withToken($token);
    }

    private function helpText(): string
    {
        return "Я подскажу, что приготовить, исходя из твоего холодильника и вкусов.\n\n"
            . "Просто напиши, что хочешь — например «что приготовить на ужин?» или «давай полегче».\n\n"
            . "Команды:\n"
            . "/new — начать новый разговор\n"
            . "/fridge — открыть холодильник (Mini App)\n"
            . "/help — эта справка";
    }
}
