<?php

declare(strict_types=1);

namespace App\Component\Telegram\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class MessageRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function create(int $chatId, int $userId, int $messageId, string $text): int
    {
        return $this->db->insert(
            "INSERT INTO messages (chat_id, user_id, telegram_message_id, raw_text)
             VALUES (?, ?, ?, ?)",
            [$chatId, $userId, $messageId, $text]
        );
    }

    public function getForChat(int $chatId, int $months, int $billingDay = 1): array
    {
        $dateFrom = $this->calculateBillingPeriodStart($months, $billingDay);

        return $this->db->query(
            "SELECT id, raw_text, created_at, categorized FROM messages
             WHERE chat_id = ? AND created_at >= ?
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

    public function countForChat(int $chatId): int
    {
        $result = $this->db->queryFirst(
            "SELECT COUNT(*) as count FROM messages WHERE chat_id = ?",
            [$chatId]
        );

        return (int) ($result['count'] ?? 0);
    }

    public function updateText(int $chatId, int $telegramMessageId, string $text): bool
    {
        return $this->db->execute(
            "UPDATE messages SET raw_text = ? WHERE chat_id = ? AND telegram_message_id = ?",
            [$text, $chatId, $telegramMessageId]
        ) > 0;
    }

    public function deleteByTelegramMessageId(int $chatId, int $telegramMessageId): bool
    {
        return $this->db->execute(
            'DELETE FROM messages WHERE chat_id = ? AND telegram_message_id = ?',
            [$chatId, $telegramMessageId]
        ) > 0;
    }

    public function updateCategorization(array $items): void
    {
        $grouped = [];
        foreach ($items as $item) {
            $id = $item['message_id'] ?? null;
            if ($id !== null) {
                $grouped[$id][] = $item;
            }
        }

        foreach ($grouped as $messageId => $positions) {
            $this->db->execute(
                "UPDATE messages SET categorized = ?::jsonb WHERE id = ?",
                [json_encode($positions, JSON_UNESCAPED_UNICODE), $messageId]
            );
        }
    }
}
