<?php

declare(strict_types=1);

namespace App\Component\Telegram\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class ChatRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function findByTelegramChatId(int $telegramChatId): ?array
    {
        $chat = $this->db->queryFirst(
            "SELECT * FROM telegram_chats WHERE telegram_chat_id = ?",
            [$telegramChatId]
        );

        if ($chat) {
            $chat['settings'] = json_decode($chat['settings'] ?? '{}', true) ?: ['mode' => 'shared'];
}

        return $chat;
    }

    public function create(array $chatData): int
    {
        return $this->db->insert(
            "INSERT INTO telegram_chats (telegram_chat_id, title, type, description)
             VALUES (?, ?, ?, ?)",
            [
                $chatData['id'],
                $chatData['title'] ?? null,
                $chatData['type'] ?? 'private',
                $chatData['description'] ?? null,
            ]
        );
    }

    public function findOrCreate(array $chat): array
    {
        $existing = $this->findByTelegramChatId($chat['id']);
        if ($existing) {
            return $existing;
        }

        $this->create($chat);

        return $this->findByTelegramChatId($chat['id']) ?? [
            'id' => 0,
            'settings' => ['mode' => 'shared'],
];
    }

    public function updateSettings(int $id, array $settings): void
    {
        $this->db->execute(
            "UPDATE telegram_chats SET settings = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($settings), $id]
        );
    }

    public function setLlmProvider(int $id, ?int $providerId): void
    {
        $this->db->execute(
            "UPDATE telegram_chats SET llm_provider_id = ?, updated_at = NOW() WHERE id = ?",
            [$providerId, $id]
        );
    }

    public function getCurrency(int $id): string
    {
        $chat = $this->db->queryFirst(
            "SELECT settings FROM telegram_chats WHERE id = ?",
            [$id]
        );

        if (!$chat) {
            return 'THB';
        }

        $settings = json_decode($chat['settings'] ?? '{}', true) ?: [];

        return $settings['currency'] ?? 'THB';
    }

    public function setCurrency(int $id, string $currency): void
    {
        $chat = $this->db->queryFirst(
            "SELECT settings FROM telegram_chats WHERE id = ?",
            [$id]
        );

        $settings = json_decode($chat['settings'] ?? '{}', true) ?: [];
        $settings['currency'] = strtoupper($currency);

        $this->updateSettings($id, $settings);
    }

    public function getCategories(int $id): ?string
    {
        $chat = $this->db->queryFirst(
            "SELECT settings FROM telegram_chats WHERE id = ?",
            [$id]
        );

        if (!$chat) {
            return null;
        }

        $settings = json_decode($chat['settings'] ?? '{}', true) ?: [];

        return $settings['categories'] ?? null;
    }

    public function setCategories(int $id, string $categories): void
    {
        $chat = $this->db->queryFirst(
            "SELECT settings FROM telegram_chats WHERE id = ?",
            [$id]
        );

        $settings = json_decode($chat['settings'] ?? '{}', true) ?: [];
        $settings['categories'] = $categories;

        $this->updateSettings($id, $settings);
    }

    public function getSettings(int $id): array
    {
        $chat = $this->db->queryFirst(
            "SELECT settings FROM telegram_chats WHERE id = ?",
            [$id]
        );

        return json_decode($chat['settings'] ?? '{}', true) ?: [];
    }

    public function getBillingDay(int $id): int
    {
        $settings = $this->getSettings($id);
        return (int) ($settings['billing_day'] ?? 1);
    }

    public function setBillingDay(int $id, int $day): void
    {
        $day = max(1, min(28, $day));
        $settings = $this->getSettings($id);
        $settings['billing_day'] = $day;
        $this->updateSettings($id, $settings);
    }

    public function getPlanningPeriod(int $id): int
    {
        $settings = $this->getSettings($id);
        return (int) ($settings['planning_period'] ?? 1);
    }

    public function setPlanningPeriod(int $id, int $months): void
    {
        $months = max(1, min(12, $months));
        $settings = $this->getSettings($id);
        $settings['planning_period'] = $months;
        $this->updateSettings($id, $settings);
    }

}
