<?php

declare(strict_types=1);

namespace App\Component\LLM\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class LlmProviderRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function findById(int $id): ?array
    {
        return $this->db->queryFirst(
            "SELECT * FROM llm_provider WHERE id = ?",
            [$id]
        );
    }

    public function findByCode(string $code): ?array
    {
        return $this->db->queryFirst(
            "SELECT * FROM llm_provider WHERE code = ?",
            [$code]
        );
    }

    public function getActive(): array
    {
        return $this->db->query(
            "SELECT * FROM llm_provider WHERE is_active = TRUE ORDER BY id"
        );
    }

    public function getDefault(): ?array
    {
        return $this->db->queryFirst(
            "SELECT * FROM llm_provider WHERE is_active = TRUE ORDER BY id LIMIT 1"
        );
    }

    public function updateHealthStatus(int $id, string $status): void
    {
        $this->db->update(
            "UPDATE llm_provider SET health_status = ?, last_health_check = NOW(), updated_at = NOW() WHERE id = ?",
            [$status, $id]
        );
    }

    public function updateConfig(int $id, array $configuration): void
    {
        $this->db->update(
            "UPDATE llm_provider SET configuration = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($configuration), $id]
        );
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->update(
            "UPDATE llm_provider SET is_active = ?, updated_at = NOW() WHERE id = ?",
            [$active, $id]
        );
    }
}
