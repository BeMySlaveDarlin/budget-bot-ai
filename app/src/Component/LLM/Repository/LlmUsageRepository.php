<?php

declare(strict_types=1);

namespace App\Component\LLM\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class LlmUsageRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function create(int $providerId, int $inputTokens, int $outputTokens): int
    {
        return $this->db->insert(
            "INSERT INTO llm_usage (provider_id, input_tokens, output_tokens) VALUES (?, ?, ?)",
            [$providerId, $inputTokens, $outputTokens]
        );
    }

    public function logUsage(string $providerCode, int $inputTokens, int $outputTokens): void
    {
        $this->db->execute(
            "INSERT INTO llm_usage (provider_id, input_tokens, output_tokens)
             SELECT id, ?, ? FROM llm_provider WHERE code = ?",
            [$inputTokens, $outputTokens, $providerCode]
        );
    }

    public function getDailyUsage(): int
    {
        $result = $this->db->queryFirst(
            "SELECT COALESCE(SUM(input_tokens + output_tokens), 0) as total
             FROM llm_usage
             WHERE created_at::date = CURRENT_DATE"
        );

        return (int) ($result['total'] ?? 0);
    }

    public function getTotalUsage(int $providerId, int $months = 1): array
    {
        $dateFrom = date('Y-m-d H:i:s', strtotime("-{$months} months"));

        $result = $this->db->queryFirst(
            "SELECT COALESCE(SUM(input_tokens), 0) as input_tokens,
                    COALESCE(SUM(output_tokens), 0) as output_tokens,
                    COUNT(*) as requests
             FROM llm_usage
             WHERE provider_id = ? AND created_at >= ?",
            [$providerId, $dateFrom]
        );

        return [
            'input_tokens' => (int) ($result['input_tokens'] ?? 0),
            'output_tokens' => (int) ($result['output_tokens'] ?? 0),
            'requests' => (int) ($result['requests'] ?? 0),
        ];
    }

    public function getUsageByDay(int $providerId, int $days = 30): array
    {
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        return $this->db->query(
            "SELECT DATE(created_at) as date,
                    SUM(input_tokens) as input_tokens,
                    SUM(output_tokens) as output_tokens,
                    COUNT(*) as requests
             FROM llm_usage
             WHERE provider_id = ? AND created_at >= ?
             GROUP BY DATE(created_at)
             ORDER BY date",
            [$providerId, $dateFrom]
        );
    }

    public function getAllProvidersUsage(int $months = 1): array
    {
        $dateFrom = date('Y-m-d H:i:s', strtotime("-{$months} months"));

        return $this->db->query(
            "SELECT p.code, p.name,
                    COALESCE(SUM(u.input_tokens), 0) as input_tokens,
                    COALESCE(SUM(u.output_tokens), 0) as output_tokens,
                    COUNT(u.id) as requests
             FROM llm_provider p
             LEFT JOIN llm_usage u ON u.provider_id = p.id AND u.created_at >= ?
             GROUP BY p.id, p.code, p.name
             ORDER BY p.id",
            [$dateFrom]
        );
    }
}
