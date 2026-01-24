<?php

declare(strict_types=1);

namespace App\Component\Telegram\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class UserRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function findByTelegramId(int $telegramId): ?array
    {
        return $this->db->queryFirst(
            "SELECT * FROM telegram_users WHERE telegram_id = ?",
            [$telegramId]
        );
    }

    public function create(array $from): int
    {
        return $this->db->insert(
            "INSERT INTO telegram_users (telegram_id, username, first_name, last_name, language_code, is_premium)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $from['id'],
                $from['username'] ?? null,
                $from['first_name'] ?? null,
                $from['last_name'] ?? null,
                $from['language_code'] ?? null,
                $from['is_premium'] ?? false,
            ]
        );
    }

    public function findOrCreate(array $from): array
    {
        if (empty($from['id'])) {
            return ['id' => 0, 'enabled' => false];
        }

        $user = $this->findByTelegramId($from['id']);
        if ($user) {
            return $user;
        }

        $id = $this->create($from);

        return $this->findByTelegramId($from['id']) ?? ['id' => $id, 'enabled' => false];
    }

    public function enable(int $id): void
    {
        $this->db->execute("UPDATE telegram_users SET enabled = true WHERE id = ?", [$id]);
    }

    public function disable(int $id): void
    {
        $this->db->execute("UPDATE telegram_users SET enabled = false WHERE id = ?", [$id]);
    }
}
