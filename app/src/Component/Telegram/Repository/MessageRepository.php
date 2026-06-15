<?php

declare(strict_types=1);

namespace App\Component\Telegram\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;

#[Injectable]
class MessageRepository
{
    public function __construct(
        private DatabaseConnection $db,
        private LoggerInterface $logger
    ) {
    }

    public function create(int $chatId, int $userId, int $messageId, string $text, ?int $topicId = null): int
    {
        return $this->db->insert(
            "INSERT INTO budget_messages (chat_id, user_id, telegram_message_id, raw_text, topic_id)
             VALUES (?, ?, ?, ?, ?)",
            [$chatId, $userId, $messageId, $text, $topicId]
        );
    }

    public function getForChat(int $chatId, int $months, int $billingDay = 1, ?int $topicId = null): array
    {
        $dateFrom = $this->calculateBillingPeriodStart($months, $billingDay);

        if ($topicId !== null) {
            return $this->db->query(
                "SELECT id, raw_text, created_at, categorized FROM budget_messages
                 WHERE chat_id = ? AND topic_id = ? AND created_at >= ?
                 ORDER BY created_at DESC",
                [$chatId, $topicId, $dateFrom]
            );
        }

        return $this->db->query(
            "SELECT id, raw_text, created_at, categorized FROM budget_messages
             WHERE chat_id = ? AND topic_id IS NULL AND created_at >= ?
             ORDER BY created_at DESC",
            [$chatId, $dateFrom]
        );
    }

    private function calculateBillingPeriodStart(int $months, int $billingDay): string
    {
        $today = new \DateTimeImmutable();
        $currentDay = (int) $today->format('d');

        if ($currentDay >= $billingDay) {
            $periodStart = $today->modify('first day of this month')->modify('+' . ($billingDay - 1) . ' days');
        } else {
            $periodStart = $today->modify('first day of last month')->modify('+' . ($billingDay - 1) . ' days');
        }

        $periodsBack = $months - 1;
        if ($periodsBack > 0) {
            $periodStart = $periodStart->modify("-{$periodsBack} months");
        }

        return $periodStart->format('Y-m-d 00:00:00');
    }

    public function countForChat(int $chatId, ?int $topicId = null): int
    {
        if ($topicId !== null) {
            $result = $this->db->queryFirst(
                "SELECT COUNT(*) as count FROM budget_messages WHERE chat_id = ? AND topic_id = ?",
                [$chatId, $topicId]
            );
        } else {
            $result = $this->db->queryFirst(
                "SELECT COUNT(*) as count FROM budget_messages WHERE chat_id = ? AND topic_id IS NULL",
                [$chatId]
            );
        }

        return (int) ($result['count'] ?? 0);
    }

    public function updateText(int $chatId, int $telegramMessageId, string $text, ?int $topicId = null): bool
    {
        if ($topicId !== null) {
            return $this->db->execute(
                "UPDATE budget_messages SET raw_text = ?, categorized = NULL WHERE chat_id = ? AND telegram_message_id = ? AND topic_id = ?",
                [$text, $chatId, $telegramMessageId, $topicId]
            ) > 0;
        }

        return $this->db->execute(
            "UPDATE budget_messages SET raw_text = ?, categorized = NULL WHERE chat_id = ? AND telegram_message_id = ? AND topic_id IS NULL",
            [$text, $chatId, $telegramMessageId]
        ) > 0;
    }

    public function deleteByTelegramMessageId(int $chatId, int $telegramMessageId, ?int $topicId = null): bool
    {
        if ($topicId !== null) {
            return $this->db->execute(
                'DELETE FROM budget_messages WHERE chat_id = ? AND telegram_message_id = ? AND topic_id = ?',
                [$chatId, $telegramMessageId, $topicId]
            ) > 0;
        }

        return $this->db->execute(
            'DELETE FROM budget_messages WHERE chat_id = ? AND telegram_message_id = ? AND topic_id IS NULL',
            [$chatId, $telegramMessageId]
        ) > 0;
    }

    public function updateCategorization(array $items): void
    {
        $this->logger->info('[MessageRepo:updateCategorization] START', [
            'total_items' => count($items),
        ]);

        $grouped = [];
        $skipped = 0;
        foreach ($items as $item) {
            $id = $item['message_id'] ?? null;
            if ($id !== null) {
                $grouped[$id][] = $item;
            } else {
                $skipped++;
            }
        }

        if ($skipped > 0) {
            $this->logger->warning('[MessageRepo:updateCategorization] Items without message_id', [
                'skipped' => $skipped,
            ]);
        }

        $this->logger->info('[MessageRepo:updateCategorization] Grouped by message', [
            'unique_messages' => count($grouped),
            'message_ids' => array_keys($grouped),
        ]);

        foreach ($grouped as $messageId => $positions) {
            $json = json_encode($positions, JSON_UNESCAPED_UNICODE);
            $this->logger->debug('[MessageRepo:updateCategorization] Saving', [
                'message_id' => $messageId,
                'positions_count' => count($positions),
                'json_length' => strlen($json),
            ]);

            $this->db->execute(
                "UPDATE budget_messages SET categorized = ?::jsonb WHERE id = ?",
                [$json, $messageId]
            );
        }

        $this->logger->info('[MessageRepo:updateCategorization] DONE');
    }
}
