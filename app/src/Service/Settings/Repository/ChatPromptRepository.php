<?php

declare(strict_types=1);

namespace App\Service\Settings\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class ChatPromptRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function getPrompt(int $chatId, string $type, ?int $topicId = null): ?string
    {
        if ($topicId !== null) {
            $row = $this->db->queryFirst(
                'SELECT prompt_text FROM chat_prompts WHERE chat_id = ? AND prompt_type = ? AND topic_id = ?',
                [$chatId, $type, $topicId]
            );
            if ($row) {
                return $row['prompt_text'];
            }
        }

        $row = $this->db->queryFirst(
            'SELECT prompt_text FROM chat_prompts WHERE chat_id = ? AND prompt_type = ? AND topic_id IS NULL',
            [$chatId, $type]
        );

        return $row['prompt_text'] ?? null;
    }

    public function setPrompt(int $chatId, string $type, string $text, ?int $topicId = null): void
    {
        if ($topicId !== null) {
            $existing = $this->db->queryFirst(
                'SELECT id FROM chat_prompts WHERE chat_id = ? AND prompt_type = ? AND topic_id = ?',
                [$chatId, $type, $topicId]
            );
        } else {
            $existing = $this->db->queryFirst(
                'SELECT id FROM chat_prompts WHERE chat_id = ? AND prompt_type = ? AND topic_id IS NULL',
                [$chatId, $type]
            );
        }

        if ($existing) {
            $this->db->execute(
                'UPDATE chat_prompts SET prompt_text = ?, updated_at = NOW() WHERE id = ?',
                [$text, $existing['id']]
            );
        } else {
            $this->db->insert(
                'INSERT INTO chat_prompts (chat_id, prompt_type, prompt_text, topic_id, updated_at) VALUES (?, ?, ?, ?, NOW())',
                [$chatId, $type, $text, $topicId]
            );
        }
    }

    public function deletePrompt(int $chatId, string $type, ?int $topicId = null): void
    {
        if ($topicId !== null) {
            $this->db->execute(
                'DELETE FROM chat_prompts WHERE chat_id = ? AND prompt_type = ? AND topic_id = ?',
                [$chatId, $type, $topicId]
            );
        } else {
            $this->db->execute(
                'DELETE FROM chat_prompts WHERE chat_id = ? AND prompt_type = ? AND topic_id IS NULL',
                [$chatId, $type]
            );
        }
    }

    public function getAllForChat(int $chatId, ?int $topicId = null): array
    {
        if ($topicId !== null) {
            return $this->db->query(
                'SELECT prompt_type, prompt_text FROM chat_prompts WHERE chat_id = ? AND (topic_id = ? OR topic_id IS NULL)',
                [$chatId, $topicId]
            );
        }

        return $this->db->query(
            'SELECT prompt_type, prompt_text FROM chat_prompts WHERE chat_id = ? AND topic_id IS NULL',
            [$chatId]
        );
    }
}
