<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Component\Telegram\Repository\ChatRepository;
use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;
use App\Service\Database\DatabaseConnection;

#[Command(name: 'migrate:messages', description: 'Copy messages from one chat to another with topic')]
final class MigrateMessagesCommand implements CommandInterface
{
    public function __construct(
        private ChatRepository $chatRepo,
        private DatabaseConnection $db,
    ) {
    }

    public function execute(array $args = []): int
    {
        $from = $this->getArg($args, '--from');
        $to = $this->getArg($args, '--to');
        $topic = $this->getArg($args, '--topic');

        if (!$from || !$to || !$topic) {
            echo "Usage: php app/bin/cli.php migrate:messages --from=<old_chat_tg_id> --to=<new_chat_tg_id> --topic=<topic_id>\n";
            return 1;
        }

        $fromChatTgId = (int) $from;
        $toChatTgId = (int) $to;
        $topicId = (int) $topic;

        $oldChat = $this->chatRepo->findByTelegramChatId($fromChatTgId);
        if (!$oldChat) {
            echo "Source chat {$fromChatTgId} not found\n";
            return 1;
        }

        $newChat = $this->chatRepo->findByTelegramChatId($toChatTgId);
        if (!$newChat) {
            echo "Target chat {$toChatTgId} not found\n";
            return 1;
        }

        $stmt = $this->db->execute(
            "INSERT INTO budget_messages (chat_id, user_id, telegram_message_id, raw_text, categorized, topic_id, created_at)
             SELECT ?, user_id, telegram_message_id, raw_text, categorized, ?, created_at
             FROM budget_messages WHERE chat_id = ?",
            [$newChat['id'], $topicId, $oldChat['id']]
        );

        $count = $stmt->rowCount();
        echo "Copied {$count} messages from chat {$fromChatTgId} to chat {$toChatTgId} topic {$topicId}\n";

        return 0;
    }

    public function getName(): string
    {
        return 'migrate:messages';
    }

    public function getDescription(): string
    {
        return 'Copy messages from one chat to another with topic';
    }

    private function getArg(array $args, string $name): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $name . '=')) {
                return substr($arg, strlen($name) + 1);
            }
        }

        return null;
    }
}
