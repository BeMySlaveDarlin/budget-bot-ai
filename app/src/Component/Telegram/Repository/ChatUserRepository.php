<?php

declare(strict_types=1);

namespace App\Component\Telegram\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class ChatUserRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function findByChatAndUser(int $chatId, int $userId): ?array
    {
        return $this->db->queryFirst(
            'SELECT * FROM telegram_chat_users WHERE chat_id = ? AND user_id = ?',
            [$chatId, $userId]
        );
    }

    public function isAdmin(int $chatId, int $userId): bool
    {
        $record = $this->findByChatAndUser($chatId, $userId);

        return (bool) ($record['is_admin'] ?? false);
    }

    public function setAdmin(int $chatId, int $userId, bool $isAdmin): void
    {
        $existing = $this->findByChatAndUser($chatId, $userId);

        if ($existing) {
            $this->db->execute(
                'UPDATE telegram_chat_users SET is_admin = ? WHERE id = ?',
                [$isAdmin, $existing['id']]
            );
        } else {
            $this->db->execute(
                'INSERT INTO telegram_chat_users (chat_id, user_id, is_admin) VALUES (?, ?, ?)',
                [$chatId, $userId, $isAdmin]
            );
        }
    }

    public function ensureExists(int $chatId, int $userId, bool $isPrivateChat = false): void
    {
        $isAdmin = $isPrivateChat;

        $this->db->execute(
            'INSERT INTO telegram_chat_users (chat_id, user_id, is_admin) VALUES (?, ?, ?)
             ON CONFLICT (chat_id, user_id) DO UPDATE SET is_admin = CASE
                 WHEN ? = true THEN true
                 ELSE telegram_chat_users.is_admin
             END',
            [$chatId, $userId, $isAdmin, $isPrivateChat]
        );
    }
}
