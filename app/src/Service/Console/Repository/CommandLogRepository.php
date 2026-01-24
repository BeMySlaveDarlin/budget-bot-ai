<?php

declare(strict_types=1);

namespace App\Service\Console\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class CommandLogRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function create(
        int $chatId,
        int $userId,
        string $command,
        string $params,
        ?string $llmResponse,
        int $inputTokens,
        int $outputTokens
    ): int {
        return $this->db->insert(
            "INSERT INTO command_logs (chat_id, user_id, command, params, llm_response, input_tokens, output_tokens)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$chatId, $userId, $command, $params, $llmResponse, $inputTokens, $outputTokens]
        );
    }

    public function getForChat(int $chatId, int $limit = 100): array
    {
        return $this->db->query(
            "SELECT * FROM command_logs WHERE chat_id = ? ORDER BY created_at DESC LIMIT ?",
            [$chatId, $limit]
        );
    }

    public function getTokenUsage(int $chatId, int $months = 1): array
    {
        $dateFrom = date('Y-m-d H:i:s', strtotime("-{$months} months"));

        $result = $this->db->queryFirst(
            "SELECT COALESCE(SUM(input_tokens), 0) as input_tokens,
                    COALESCE(SUM(output_tokens), 0) as output_tokens
             FROM command_logs
             WHERE chat_id = ? AND created_at >= ?",
            [$chatId, $dateFrom]
        );

        return [
            'input_tokens' => (int) ($result['input_tokens'] ?? 0),
            'output_tokens' => (int) ($result['output_tokens'] ?? 0),
        ];
    }
}
