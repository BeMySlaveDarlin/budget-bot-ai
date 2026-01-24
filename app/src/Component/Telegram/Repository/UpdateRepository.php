<?php

declare(strict_types=1);

namespace App\Component\Telegram\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class UpdateRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function create(int $updateId, string $type, array $rawJson): int
    {
        return $this->db->insert(
            "INSERT INTO telegram_updates (update_id, type, raw_json)
             VALUES (?, ?, ?)
             ON CONFLICT (update_id) DO NOTHING",
            [$updateId, $type, json_encode($rawJson)]
        );
    }

    public function markProcessed(int $updateId, ?array $result = null): void
    {
        $this->db->update(
            "UPDATE telegram_updates SET processed = TRUE, result = ? WHERE update_id = ?",
            [json_encode($result), $updateId]
        );
    }

    public function getUnprocessed(int $limit = 100): array
    {
        return $this->db->query(
            "SELECT * FROM telegram_updates WHERE processed = FALSE ORDER BY update_id ASC LIMIT ?",
            [$limit]
        );
    }

    public function findByUpdateId(int $updateId): ?array
    {
        return $this->db->queryFirst(
            "SELECT * FROM telegram_updates WHERE update_id = ?",
            [$updateId]
        );
    }
}
