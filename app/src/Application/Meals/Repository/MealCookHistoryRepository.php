<?php

declare(strict_types=1);

namespace App\Application\Meals\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
final class MealCookHistoryRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function getRecent(int $chatId, int $days = 14): array
    {
        return $this->db->query(
            "SELECT dish_name, cooked_at
             FROM meal_cook_history
             WHERE chat_id = ? AND cooked_at >= NOW() - (? * INTERVAL '1 day')
             ORDER BY cooked_at DESC",
            [$chatId, $days]
        );
    }

    public function getAll(int $chatId, int $limit = 100): array
    {
        return $this->db->query(
            "SELECT id, dish_name, cooked_at
             FROM meal_cook_history
             WHERE chat_id = ?
             ORDER BY cooked_at DESC
             LIMIT ?",
            [$chatId, $limit]
        );
    }
}
