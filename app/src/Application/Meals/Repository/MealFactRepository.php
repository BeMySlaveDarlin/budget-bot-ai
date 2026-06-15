<?php

declare(strict_types=1);

namespace App\Application\Meals\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
final class MealFactRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function getActive(int $chatId): array
    {
        return $this->db->query(
            "SELECT fact, source
             FROM meal_facts
             WHERE chat_id = ? AND is_active = TRUE
             ORDER BY id",
            [$chatId]
        );
    }

    public function getAllForChat(int $chatId, bool $includeInactive = false): array
    {
        $filter = $includeInactive ? '' : ' AND is_active = TRUE';

        return $this->db->query(
            "SELECT id, fact, source, is_active, created_at, updated_at
             FROM meal_facts
             WHERE chat_id = ?{$filter}
             ORDER BY id",
            [$chatId]
        );
    }

    public function findById(int $id, int $chatId): ?array
    {
        return $this->db->queryFirst(
            "SELECT id, fact, source, is_active, superseded_by
             FROM meal_facts
             WHERE id = ? AND chat_id = ?",
            [$id, $chatId]
        );
    }

    public function create(int $chatId, string $fact, string $source = 'manual'): int
    {
        return $this->db->insert(
            "INSERT INTO meal_facts (chat_id, fact, source, is_active, created_at, updated_at)
             VALUES (?, ?, ?, TRUE, NOW(), NOW())
             RETURNING id",
            [$chatId, $fact, $source]
        );
    }

    public function updateText(int $id, int $chatId, string $fact): bool
    {
        return $this->db->update(
            "UPDATE meal_facts
             SET fact = ?, updated_at = NOW()
             WHERE id = ? AND chat_id = ?",
            [$fact, $id, $chatId]
        ) > 0;
    }

    public function deactivate(int $id, int $chatId): bool
    {
        return $this->db->update(
            "UPDATE meal_facts
             SET is_active = FALSE, updated_at = NOW()
             WHERE id = ? AND chat_id = ?",
            [$id, $chatId]
        ) > 0;
    }
}
