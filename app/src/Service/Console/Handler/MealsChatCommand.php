<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Application\Meals\Service\MealChatService;
use App\Application\Meals\Service\MealSessionService;
use App\Component\Telegram\Repository\ChatRepository;
use App\Component\Telegram\Repository\ChatUserRepository;
use App\Component\Telegram\Repository\UserRepository;
use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;

#[Command(name: 'meals:chat', description: 'Run the meals hot path for a chat without Telegram (test harness)')]
final class MealsChatCommand implements CommandInterface
{
    public function __construct(
        private ChatRepository $chatRepo,
        private UserRepository $userRepo,
        private ChatUserRepository $chatUserRepo,
        private MealSessionService $session,
        private MealChatService $chat
    ) {
    }

    public function execute(array $args = []): int
    {
        $tgChatId = (int) ($args[0] ?? 0);
        $text = trim(implode(' ', array_slice($args, 1)));

        if ($tgChatId === 0 || $text === '') {
            echo "Usage: meals:chat <telegram_chat_id> <text>\n";

            return 1;
        }

        $chat = $this->chatRepo->findOrCreate(['id' => $tgChatId, 'type' => 'private', 'title' => 'meals-cli']);
        $user = $this->userRepo->findOrCreate(['id' => $tgChatId, 'username' => 'cli', 'first_name' => 'CLI']);
        $this->chatUserRepo->ensureExists((int) $chat['id'], (int) $user['id'], true);

        $sessionId = $this->session->current((int) $chat['id']);

        echo "chat_id={$chat['id']} user_id={$user['id']} session={$sessionId}\n";
        echo "USER> {$text}\n";

        $reply = $this->chat->handle((int) $chat['id'], (int) $user['id'], null, $sessionId, $text);

        if ($reply->openFridge) {
            echo "BOT>  [открывает холодильник кнопкой]\n";
        }
        if ($reply->text !== null && $reply->text !== '') {
            echo "BOT>  {$reply->text}\n";
        }

        return 0;
    }

    public function getName(): string
    {
        return 'meals:chat';
    }

    public function getDescription(): string
    {
        return 'Run the meals hot path for a chat without Telegram (test harness)';
    }
}
