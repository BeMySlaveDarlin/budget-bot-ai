<?php

declare(strict_types=1);

namespace App\Application\Budget\Task;

use App\Application\Budget\DTO\CommandContext;
use App\Application\Budget\Handler\CallbackHandler;
use App\Application\Budget\Handler\CommandDispatcher;
use App\Application\Budget\Handler\MessageHandler;
use App\Component\Telegram\Repository\ChatRepository;
use App\Component\Telegram\Repository\ChatUserRepository;
use App\Component\Telegram\Repository\UpdateRepository;
use App\Component\Telegram\Repository\UserRepository;
use App\Component\Telegram\TelegramClient;
use App\Service\Swoole\Task\Handler\AbstractTask;

class WebhookProcessTask extends AbstractTask
{
    protected int $maxRetries = 2;

    public function handle(): mixed
    {
        $update = $this->payload['update'] ?? [];
        if (empty($update)) {
            return ['status' => 'error', 'message' => 'Empty update'];
        }

        $updateRepo = $this->getService(UpdateRepository::class);
        $updateId = $update['update_id'] ?? 0;
        $type = match (true) {
            isset($update['callback_query']) => 'callback_query',
            isset($update['edited_message']) => 'edited_message',
            default => 'message',
        };
        $updateRepo->create($updateId, $type, $update);

        if (isset($update['callback_query'])) {
            $this->getService(CallbackHandler::class)->handle($update['callback_query']);
            $updateRepo->markProcessed($updateId);

            return ['status' => 'ok', 'type' => 'callback'];
        }

        if (isset($update['edited_message'])) {
            $this->handleEditedMessage($update['edited_message']);
            $updateRepo->markProcessed($updateId);

            return ['status' => 'ok', 'type' => 'edited_message'];
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return ['status' => 'ok', 'type' => 'no_message'];
        }

        $text = trim($message['text'] ?? '');
        $chatTg = $message['chat'];
        $fromTg = $message['from'] ?? [];
        $messageId = $message['message_id'];

        $userRepo = $this->getService(UserRepository::class);
        $chatRepo = $this->getService(ChatRepository::class);
        $chatUserRepo = $this->getService(ChatUserRepository::class);

        $user = $userRepo->findOrCreate($fromTg);
        $chat = $chatRepo->findOrCreate($chatTg);

        $isPrivateChat = ($chatTg['type'] ?? '') === 'private';
        $chatUserRepo->ensureExists($chat['id'], $user['id'], $isPrivateChat);

        $statsAliases = ['посчитай', 'статистика', 'расчет', 'бюджет', 'stats', 'статс'];
        $statsPhrases = ['сколько потратил', 'что по деньгам', 'как с бюджетом', 'покажи расходы', 'покажи статистику'];
        $detailsAliases = ['детали', 'детально', 'подробно', 'расклад', 'отчет', 'отчёт'];
        $detailsPhrases = ['полный отчет', 'подробный отчет', 'подробный отчёт', 'все расходы', 'покажи всё'];
        $lowerText = mb_strtolower($text);

        $replyTo = $message['reply_to_message'] ?? null;
        $deletePatterns = ['del', 'удалить'];

        if ($replyTo && in_array($lowerText, $deletePatterns, true)) {
            $this->getService(MessageHandler::class)->handleDelete($chat['id'], $replyTo['message_id']);
            $updateRepo->markProcessed($updateId);

            return ['status' => 'ok', 'type' => 'delete'];
        }

        if (str_starts_with($text, '/')) {
            $this->handleCommand($text, $chat, $user, $chatTg['id'], $messageId);
        } elseif (in_array($lowerText, $detailsAliases, true) || $this->matchesAny($lowerText, $detailsPhrases)) {
            $this->handleCommand('/stats 1 v', $chat, $user, $chatTg['id'], $messageId);
        } elseif (in_array($lowerText, $statsAliases, true) || $this->matchesAny($lowerText, $statsPhrases)) {
            $this->handleCommand('/stats', $chat, $user, $chatTg['id'], $messageId);
        } else {
            $this->getService(MessageHandler::class)->handle($chat['id'], $user['id'], $messageId, $text);
        }

        $updateRepo->markProcessed($updateId);

        return ['status' => 'ok', 'type' => 'message'];
    }

    private function handleCommand(string $text, array $chat, array $user, int $chatTgId, int $messageId): void
    {
        $parts = preg_split('/\s+/', trim($text), 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        $chatUserRepo = $this->getService(ChatUserRepository::class);
        $isAdmin = $chatUserRepo->isAdmin($chat['id'], $user['id']);

        $ctx = new CommandContext(
            chat: $chat,
            user: $user,
            command: $command,
            args: $args,
            telegramChatId: $chatTgId,
            messageId: $messageId,
            isAdmin: $isAdmin,
            isEnabled: (bool) ($user['enabled'] ?? false)
        );

        $dispatcher = $this->getService(CommandDispatcher::class);
        $telegram = $this->getService(TelegramClient::class);

        $meta = $dispatcher->getCommandMeta($command);
        $pendingMessageId = null;

        if ($meta && $meta['showPending']) {
            $response = $telegram->sendMessage($chatTgId, $meta['pendingMessage']);
            $pendingMessageId = $response['result']['message_id'] ?? null;
        }

        $result = $dispatcher->dispatch($ctx);

        if ($result && $result['text']) {
            if ($pendingMessageId) {
                $telegram->editMessageText($chatTgId, $pendingMessageId, $result['text']);
            } elseif (!empty($result['keyboard'])) {
                $telegram->sendMessageWithKeyboard($chatTgId, $result['text'], $result['keyboard']);
            } else {
                $telegram->sendMessage($chatTgId, $result['text']);
            }
        }
    }

    private function handleEditedMessage(array $editedMessage): void
    {
        $text = trim($editedMessage['text'] ?? '');
        if ($text === '' || str_starts_with($text, '/')) {
            return;
        }

        $chatTg = $editedMessage['chat'];
        $messageId = $editedMessage['message_id'];

        $chatRepo = $this->getService(ChatRepository::class);
        $chat = $chatRepo->findByTelegramChatId($chatTg['id']);
        if (!$chat) {
            return;
        }

        $this->getService(MessageHandler::class)->handleEdit($chat['id'], $messageId, $text);
    }

    private function matchesAny(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
