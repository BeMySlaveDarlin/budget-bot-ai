<?php

declare(strict_types=1);

namespace App\Application\Meals\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
final class MealInventoryRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function getForChat(int $chatId): array
    {
        return $this->db->query(
            "SELECT name, available, quantity, unit
             FROM meal_inventory
             WHERE chat_id = ?
             ORDER BY name",
            [$chatId]
        );
    }

    public function getForChatFull(int $chatId): array
    {
        return $this->db->query(
            "SELECT id, name, available, quantity, unit, updated_at
             FROM meal_inventory
             WHERE chat_id = ?
             ORDER BY name",
            [$chatId]
        );
    }

    public function existsByName(int $chatId, string $name): bool
    {
        $row = $this->db->queryFirst(
            "SELECT EXISTS(
                SELECT 1 FROM meal_inventory WHERE chat_id = ? AND name = ?
             ) AS found",
            [$chatId, $name]
        );

        return (bool) ($row['found'] ?? false);
    }

    public function create(int $chatId, string $name, bool $available, ?float $quantity, ?string $unit): int
    {
        return $this->db->insert(
            "INSERT INTO meal_inventory (chat_id, name, available, quantity, unit, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             RETURNING id",
            [$chatId, $name, $available, $quantity, $unit]
        );
    }

    public function update(int $id, int $chatId, string $name, bool $available, ?float $quantity, ?string $unit): bool
    {
        return $this->db->update(
            "UPDATE meal_inventory
             SET name = ?, available = ?, quantity = ?, unit = ?, updated_at = NOW()
             WHERE id = ? AND chat_id = ?",
            [$name, $available, $quantity, $unit, $id, $chatId]
        ) > 0;
    }

    public function setAvailability(int $id, int $chatId, bool $available): bool
    {
        return $this->db->update(
            "UPDATE meal_inventory
             SET available = ?, updated_at = NOW()
             WHERE id = ? AND chat_id = ?",
            [$available, $id, $chatId]
        ) > 0;
    }

    public function delete(int $id, int $chatId): bool
    {
        return $this->db->delete(
            "DELETE FROM meal_inventory WHERE id = ? AND chat_id = ?",
            [$id, $chatId]
        ) > 0;
    }

    public function upsertByName(int $chatId, string $name, bool $available, ?float $quantity, ?string $unit): void
    {
        $this->db->execute(
            "INSERT INTO meal_inventory (chat_id, name, available, quantity, unit, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON CONFLICT (chat_id, name) DO UPDATE
             SET available = EXCLUDED.available,
                 quantity = COALESCE(EXCLUDED.quantity, meal_inventory.quantity),
                 unit = COALESCE(EXCLUDED.unit, meal_inventory.unit),
                 updated_at = NOW()",
            [$chatId, $name, $available, $quantity, $unit]
        );
    }

    public function setAvailabilityByName(int $chatId, string $name, bool $available): bool
    {
        return $this->db->update(
            "UPDATE meal_inventory SET available = ?, updated_at = NOW()
             WHERE chat_id = ? AND name = ?",
            [$available, $chatId, $name]
        ) > 0;
    }
}
