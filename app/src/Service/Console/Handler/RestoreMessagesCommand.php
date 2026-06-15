<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Component\Telegram\Repository\ChatRepository;
use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;
use App\Service\Database\DatabaseConnection;

#[Command(name: 'restore:messages', description: 'Restore raw_text for migrated messages from telegram_updates')]
final class RestoreMessagesCommand implements CommandInterface
{
    public function __construct(
        private ChatRepository $chatRepo,
        private DatabaseConnection $db,
    ) {
    }

    public function execute(array $args = []): int
    {
        $oldChatTgId = $this->getArg($args, '--old-chat');
        $newChatTgId = $this->getArg($args, '--new-chat');
        $topicId = $this->getArg($args, '--topic');
        $dryRun = in_array('--dry-run', $args, true);

        if (!$oldChatTgId || !$newChatTgId || !$topicId) {
            echo "Usage: php app/bin/cli.php restore:messages --old-chat=<tg_id> --new-chat=<tg_id> --topic=<topic_id> [--dry-run]\n";
            echo "\nRestores raw_text for messages using telegram_updates as source of truth.\n";
            echo "Handles both migrated (old chat) and new (new chat) messages separately.\n";
            return 1;
        }

        $oldChat = $this->chatRepo->findByTelegramChatId((int) $oldChatTgId);
        $newChat = $this->chatRepo->findByTelegramChatId((int) $newChatTgId);

        if (!$oldChat) {
            echo "Old chat {$oldChatTgId} not found\n";
            return 1;
        }
        if (!$newChat) {
            echo "New chat {$newChatTgId} not found\n";
            return 1;
        }

        $migrationDate = $this->findMigrationDate((int) $newChatTgId);
        echo "Migration date: {$migrationDate}\n\n";

        $migratedCorrupted = $this->findCorrupted(
            (int) $newChat['id'], (int) $topicId, (int) $oldChatTgId, $migrationDate, 'before'
        );
        $newCorrupted = $this->findCorrupted(
            (int) $newChat['id'], (int) $topicId, (int) $newChatTgId, $migrationDate, 'after'
        );

        if (empty($migratedCorrupted) && empty($newCorrupted)) {
            echo "No corrupted messages found.\n";
            return 0;
        }

        $total = 0;

        if (!empty($migratedCorrupted)) {
            echo "=== Migrated messages (before {$migrationDate}) ===\n";
            $total += $this->processMessages($migratedCorrupted, $dryRun);
        }

        if (!empty($newCorrupted)) {
            echo "=== New messages (after {$migrationDate}) ===\n";
            $total += $this->processMessages($newCorrupted, $dryRun);
        }

        if ($dryRun) {
            echo "\n[DRY RUN] {$total} messages would be restored. Remove --dry-run to apply.\n";
        } else {
            echo "\nRestored {$total} messages. Categorization reset, will be recalculated on next /stats.\n";
        }

        return 0;
    }

    private function findMigrationDate(int $newChatTgId): string
    {
        $result = $this->db->queryFirst(
            "SELECT min(created_at) AS first_update FROM telegram_updates
             WHERE type = 'message' AND (raw_json->'message'->'chat'->>'id')::BIGINT = ?",
            [$newChatTgId]
        );

        return $result['first_update'] ?? date('Y-m-d H:i:s');
    }

    private function findCorrupted(int $chatId, int $topicId, int $sourceChatTgId, string $migrationDate, string $period): array
    {
        $dateFilter = $period === 'before'
            ? 'AND m.created_at < ?'
            : 'AND m.created_at >= ?';

        return $this->db->query(
            <<<SQL
            WITH source_texts AS (
                SELECT
                    (raw_json->'message'->>'message_id')::BIGINT AS tg_message_id,
                    raw_json->'message'->>'text' AS original_text
                FROM telegram_updates
                WHERE type = 'message'
                  AND (raw_json->'message'->'chat'->>'id')::BIGINT = ?
                  AND raw_json->'message'->>'text' IS NOT NULL
                  AND raw_json->'message'->>'text' ~ '\d'
            )
            SELECT m.id, m.telegram_message_id, m.created_at, m.raw_text AS current_text, st.original_text
            FROM budget_messages m
            JOIN source_texts st ON st.tg_message_id = m.telegram_message_id
            WHERE m.chat_id = ? AND m.topic_id = ?
              {$dateFilter}
              AND m.raw_text != st.original_text
            ORDER BY m.created_at
            SQL,
            [$sourceChatTgId, $chatId, $topicId, $migrationDate]
        );
    }

    private function processMessages(array $rows, bool $dryRun): int
    {
        $count = 0;
        foreach ($rows as $row) {
            echo "  ID={$row['id']} tg_msg_id={$row['telegram_message_id']} ({$row['created_at']})\n";
            echo "    Current:  " . mb_substr($row['current_text'], 0, 80) . "\n";
            echo "    Original: " . mb_substr($row['original_text'], 0, 80) . "\n";

            if (!$dryRun) {
                $this->db->execute(
                    "UPDATE budget_messages SET raw_text = ?, categorized = NULL WHERE id = ?",
                    [$row['original_text'], $row['id']]
                );
            }
            $count++;
        }

        echo "  Subtotal: {$count}\n\n";
        return $count;
    }

    public function getName(): string
    {
        return 'restore:messages';
    }

    public function getDescription(): string
    {
        return 'Restore raw_text for migrated messages from telegram_updates';
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
