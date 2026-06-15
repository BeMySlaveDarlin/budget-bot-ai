<?php

declare(strict_types=1);

namespace App\Application\Meals\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
final class BotConfigRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function resolveProvider(string $botCode): ?array
    {
        return $this->db->queryFirst(
            "
            SELECT lp.*
            FROM bot_config bc
            JOIN llm_provider lp ON lp.id = bc.provider_id
            WHERE bc.bot_code = ?
              AND bc.is_active = TRUE
              AND lp.is_active = TRUE
        ",
            [$botCode]
        );
    }

    public function getBoundTopicId(string $botCode): ?int
    {
        $row = $this->db->queryFirst(
            "SELECT configuration->>'topic_id' AS topic_id FROM bot_config WHERE bot_code = ?",
            [$botCode]
        );

        $value = $row['topic_id'] ?? null;

        return $value !== null ? (int) $value : null;
    }

    public function setBoundTopicId(string $botCode, ?int $topicId): void
    {
        $this->db->execute(
            "UPDATE bot_config
             SET configuration = jsonb_set(COALESCE(configuration, '{}'::jsonb), '{topic_id}', ?::jsonb, true),
                 updated_at = NOW()
             WHERE bot_code = ?",
            [$topicId === null ? 'null' : (string) $topicId, $botCode]
        );
    }
}
