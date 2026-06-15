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
}
