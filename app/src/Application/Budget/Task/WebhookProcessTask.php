<?php

declare(strict_types=1);

namespace App\Application\Budget\Task;

use App\Application\Budget\DTO\CommandContext;
use App\Application\Budget\Handler\CallbackHandler;
use App\Application\Budget\Handler\CommandDispatcher;
use App\Application\Budget\Handler\MessageHandler;
use App\Component\Telegram\Repository\BotConfigRepository;
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

        $updateTopicId = $update['message']['message_thread_id']
            ?? $update['edited_message']['message_thread_id']
            ?? $update['callback_query']['message']['message_thread_id']
            ?? null;

        $boundTopicId = $this->getService(BotConfigRepository::class)->getBoundTopicId('budget');
        if ($boundTopicId !== null && $updateTopicId !== $boundTopicId) {
            return ['status' => 'ok', 'type' => 'ignored_topic'];
        }

        $this->getLogger()->info('[Webhook] Processing update', [
            'update_id' => $updateId,
            'type' => $type,
        ]);

        $updateRepo->create($updateId, $type, $update);

        if (isset($update['callback_query'])) {
            $callback = $update['callback_query'];
            $callbackTopicId = $callback['message']['message_thread_id'] ?? null;
            $this->getService(CallbackHandler::class)->handle($callback, $callbackTopicId);
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
        $topicId = $message['message_thread_id'] ?? null;

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
        $viewAliases = ['сайт', 'ссылка', 'апп'];
        $lowerText = mb_strtolower($text);

        $replyTo = $message['reply_to_message'] ?? null;
        $deletePatterns = ['del', 'удалить', 'удали', 'отмена'];

        if ($replyTo && in_array($lowerText, $deletePatterns, true)) {
            $this->getService(MessageHandler::class)->handleDelete($chat['id'], $replyTo['message_id'], $topicId);
            $updateRepo->markProcessed($updateId);

            return ['status' => 'ok', 'type' => 'delete'];
        }

        $this->getLogger()->info('[Webhook] Message received', [
            'chat_id' => $chat['id'],
            'user_id' => $user['id'],
            'is_command' => str_starts_with($text, '/'),
            'text_length' => mb_strlen($text),
        ]);

        if (str_starts_with($text, '/')) {
            $this->handleCommand($text, $chat, $user, $chatTg['id'], $messageId, $topicId);
        } elseif (in_array($lowerText, $viewAliases, true)) {
            $this->handleCommand('/view', $chat, $user, $chatTg['id'], $messageId, $topicId);
        } elseif (in_array($lowerText, $detailsAliases, true) || $this->matchesAny($lowerText, $detailsPhrases)) {
            $this->handleCommand('/stats v', $chat, $user, $chatTg['id'], $messageId, $topicId);
        } elseif (in_array($lowerText, $statsAliases, true) || $this->matchesAny($lowerText, $statsPhrases)) {
            $this->handleCommand('/stats', $chat, $user, $chatTg['id'], $messageId, $topicId);
        } else {
            $this->getService(MessageHandler::class)->handle($chat['id'], $user['id'], $messageId, $text, $topicId);
        }

        $updateRepo->markProcessed($updateId);

        return ['status' => 'ok', 'type' => 'message'];
    }

    private function handleCommand(string $text, array $chat, array $user, int $chatTgId, int $messageId, ?int $topicId = null): void
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
            isEnabled: (bool) ($user['enabled'] ?? false),
            topicId: $topicId
        );

        $dispatcher = $this->getService(CommandDispatcher::class);
        $telegram = $this->getService(TelegramClient::class);

        $meta = $dispatcher->getCommandMeta($command);
        $pendingMessageId = null;

        if ($meta && $meta['showPending']) {
            $response = $telegram->sendMessage($chatTgId, $meta['pendingMessage'], messageThreadId: $topicId);
            $pendingMessageId = $response['result']['message_id'] ?? null;
        }

        $result = $dispatcher->dispatch($ctx);

        if ($result && $result['text']) {
            $chunks = $telegram->splitMessage($result['text']);

            if (count($chunks) === 1) {
                if ($pendingMessageId) {
                    if (!empty($result['keyboard'])) {
                        $telegram->deleteMessage($chatTgId, $pendingMessageId);
                        $telegram->sendMessageWithKeyboard($chatTgId, $chunks[0], $result['keyboard'], messageThreadId: $topicId);
                    } else {
                        $telegram->editMessageText($chatTgId, $pendingMessageId, $chunks[0]);
                    }
                } elseif (!empty($result['keyboard'])) {
                    $telegram->sendMessageWithKeyboard($chatTgId, $chunks[0], $result['keyboard'], messageThreadId: $topicId);
                } else {
                    $telegram->sendMessage($chatTgId, $chunks[0], messageThreadId: $topicId);
                }
            } else {
                foreach ($chunks as $i => $chunk) {
                    if ($i === 0 && $pendingMessageId) {
                        $telegram->editMessageText($chatTgId, $pendingMessageId, $chunk);
                    } else {
                        $telegram->sendMessage($chatTgId, $chunk, messageThreadId: $topicId);
                    }
                }
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
        $topicId = $editedMessage['message_thread_id'] ?? null;

        $chatRepo = $this->getService(ChatRepository::class);
        $chat = $chatRepo->findByTelegramChatId($chatTg['id']);
        if (!$chat) {
            return;
        }

        $this->getService(MessageHandler::class)->handleEdit($chat['id'], $messageId, $text, $topicId);
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
