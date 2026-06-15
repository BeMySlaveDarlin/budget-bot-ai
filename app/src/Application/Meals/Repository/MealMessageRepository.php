<?php

declare(strict_types=1);

namespace App\Application\Meals\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
final class MealMessageRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function create(
        int $chatId,
        ?int $userId,
        ?int $topicId,
        ?int $sessionId,
        string $role,
        string $content
    ): int {
        return $this->db->insert(
            "INSERT INTO meal_messages (chat_id, user_id, topic_id, session_id, role, content)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$chatId, $userId, $topicId, $sessionId, $role, $content]
        );
    }

    public function getSessionMessages(int $chatId, int $sessionId, int $limit = 20): array
    {
        $rows = $this->db->query(
            "
            SELECT role, content, created_at
            FROM meal_messages
            WHERE chat_id = ? AND session_id = ?
            ORDER BY id DESC
            LIMIT ?
        ",
            [$chatId, $sessionId, $limit]
        );

        return array_reverse($rows);
    }
}
