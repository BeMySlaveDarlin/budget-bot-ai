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

    public function getPrompt(int $chatId, string $type): ?string
    {
        $row = $this->db->queryFirst(
            'SELECT prompt_text FROM chat_prompts WHERE chat_id = ? AND prompt_type = ?',
            [$chatId, $type]
        );

        return $row['prompt_text'] ?? null;
    }

    public function setPrompt(int $chatId, string $type, string $text): void
    {
        $this->db->execute(
            'INSERT INTO chat_prompts (chat_id, prompt_type, prompt_text, updated_at)
             VALUES (?, ?, ?, NOW())
             ON CONFLICT (chat_id, prompt_type) DO UPDATE SET prompt_text = ?, updated_at = NOW()',
            [$chatId, $type, $text, $text]
        );
    }

    public function deletePrompt(int $chatId, string $type): void
    {
        $this->db->execute(
            'DELETE FROM chat_prompts WHERE chat_id = ? AND prompt_type = ?',
            [$chatId, $type]
        );
    }

    public function getAllForChat(int $chatId): array
    {
        return $this->db->query(
            'SELECT prompt_type, prompt_text FROM chat_prompts WHERE chat_id = ?',
            [$chatId]
        );
    }
}
