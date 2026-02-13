<?php

declare(strict_types=1);

namespace App\Application\Report\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
final class ReportRepository
{
    private const string TRANSACTIONS_CTE = <<<'SQL'
        WITH transactions AS (
            SELECT m.id, m.chat_id, m.created_at,
                t.idx AS item_index,
                (t.value->>'type') AS type,
                (t.value->>'category') AS category,
                (t.value->>'amount')::NUMERIC AS amount,
                (t.value->>'currency') AS currency,
                (t.value->>'description') AS description,
                (t.value->>'wallet') AS wallet
            FROM messages m
            CROSS JOIN LATERAL jsonb_array_elements(m.categorized) WITH ORDINALITY AS t(value, idx)
            WHERE m.chat_id = ? AND m.categorized IS NOT NULL
              AND m.created_at BETWEEN ? AND ?
        )
    SQL;

    public function __construct(
        private DatabaseConnection $db
    ) {}

    public function getSummary(int $chatId, string $from, string $to): array
    {
        $sql = self::TRANSACTIONS_CTE . <<<'SQL'
            SELECT type, currency, SUM(amount) AS total, COUNT(*) AS count
            FROM transactions
            GROUP BY type, currency
            ORDER BY type, currency
        SQL;

        return $this->db->query($sql, [$chatId, $from, $to]);
    }

    public function getCategoryBreakdown(int $chatId, string $from, string $to, ?string $type = null): array
    {
        $params = [$chatId, $from, $to];

        $typeFilter = '';
        if ($type !== null) {
            $typeFilter = 'WHERE type = ?';
            $params[] = $type;
        }

        $sql = self::TRANSACTIONS_CTE . <<<SQL
            SELECT category, type, currency, SUM(amount) AS total, COUNT(*) AS count
            FROM transactions
            {$typeFilter}
            GROUP BY category, type, currency
            ORDER BY total DESC
        SQL;

        return $this->db->query($sql, $params);
    }

    public function getTransactions(
        int $chatId,
        string $from,
        string $to,
        ?string $type = null,
        ?string $category = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $params = [$chatId, $from, $to];
        $filters = [];

        if ($type !== null) {
            $filters[] = 'type = ?';
            $params[] = $type;
        }

        if ($category !== null) {
            $filters[] = 'category = ?';
            $params[] = $category;
        }

        $whereClause = !empty($filters) ? 'WHERE ' . implode(' AND ', $filters) : '';

        $sql = self::TRANSACTIONS_CTE . <<<SQL
            SELECT *, COUNT(*) OVER() AS total_count
            FROM transactions
            {$whereClause}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        SQL;

        $params[] = $limit;
        $params[] = $offset;

        return $this->db->query($sql, $params);
    }

    public function getMonthlyTrends(int $chatId, string $from, string $to): array
    {
        $sql = self::TRANSACTIONS_CTE . <<<'SQL'
            SELECT TO_CHAR(created_at, 'YYYY-MM') AS month, type, currency, SUM(amount) AS total, COUNT(*) AS count
            FROM transactions
            GROUP BY month, type, currency
            ORDER BY month, type
        SQL;

        return $this->db->query($sql, [$chatId, $from, $to]);
    }

    public function getWalletBalances(int $chatId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT ON (t.value->>'wallet')
                (t.value->>'wallet') AS wallet,
                (t.value->>'amount')::NUMERIC AS amount,
                (t.value->>'currency') AS currency,
                m.created_at
            FROM messages m
            CROSS JOIN LATERAL jsonb_array_elements(m.categorized) AS t(value)
            WHERE m.chat_id = ? AND m.categorized IS NOT NULL
                AND t.value->>'type' = 'balance'
            ORDER BY t.value->>'wallet', m.created_at DESC
        SQL;

        return $this->db->query($sql, [$chatId]);
    }

    public function getLatestBalances(int $chatId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT ON (t.value->>'wallet')
                m.id AS message_id,
                (t.value->>'wallet') AS wallet,
                (t.value->>'amount')::NUMERIC AS amount,
                (t.value->>'currency') AS currency
            FROM messages m
            CROSS JOIN LATERAL jsonb_array_elements(m.categorized) AS t(value)
            WHERE m.chat_id = ? AND m.categorized IS NOT NULL
                AND t.value->>'type' = 'balance'
            ORDER BY t.value->>'wallet', m.id DESC
        SQL;

        return $this->db->query($sql, [$chatId]);
    }

    public function getTransactionsAfterMessage(int $chatId, int $afterMessageId): array
    {
        $sql = <<<'SQL'
            SELECT (t.value->>'type') AS type,
                   (t.value->>'amount')::NUMERIC AS amount,
                   (t.value->>'currency') AS currency
            FROM messages m
            CROSS JOIN LATERAL jsonb_array_elements(m.categorized) AS t(value)
            WHERE m.chat_id = ? AND m.categorized IS NOT NULL
                AND m.id > ?
                AND t.value->>'type' IN ('income', 'expense')
        SQL;

        return $this->db->query($sql, [$chatId, $afterMessageId]);
    }

    public function getTransactionsForExport(int $chatId, string $from, string $to): array
    {
        $sql = self::TRANSACTIONS_CTE . <<<'SQL'
            SELECT type, category, amount, currency, description, wallet, created_at
            FROM transactions
            ORDER BY created_at DESC
        SQL;

        return $this->db->query($sql, [$chatId, $from, $to]);
    }

    public function clearCategorization(int $chatId, string $from, string $to): int
    {
        return $this->db->update(
            "UPDATE messages SET categorized = NULL, updated_at = NOW() WHERE chat_id = ? AND created_at BETWEEN ? AND ? AND categorized IS NOT NULL",
            [$chatId, $from, $to]
        );
    }

    public function createTransaction(int $chatId, int $userId, array $data): int
    {
        $item = json_encode([[
            'type' => $data['type'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'category' => $data['category'],
            'description' => $data['description'],
            'wallet' => $data['wallet'] ?? null,
        ]], JSON_UNESCAPED_UNICODE);

        return $this->db->insert(
            "INSERT INTO messages (chat_id, user_id, telegram_message_id, raw_text, categorized, created_at, updated_at) VALUES (?, ?, NULL, ?, ?::jsonb, NOW(), NOW())",
            [$chatId, $userId, $data['description'], $item]
        );
    }

    public function updateTransaction(int $messageId, int $itemIndex, array $data): bool
    {
        $element = json_encode([
            'type' => $data['type'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'category' => $data['category'],
            'description' => $data['description'],
            'wallet' => $data['wallet'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        $arrayIndex = $itemIndex - 1;

        $rowsAffected = $this->db->update(
            "UPDATE messages SET categorized = jsonb_set(categorized, ARRAY[?::text], ?::jsonb), raw_text = ?, updated_at = NOW() WHERE id = ?",
            [(string) $arrayIndex, $element, $data['description'], $messageId]
        );

        return $rowsAffected > 0;
    }

    public function deleteTransaction(int $messageId, int $itemIndex): bool
    {
        $arrayIndex = $itemIndex - 1;

        $row = $this->db->queryFirst(
            "SELECT jsonb_array_length(categorized) AS len FROM messages WHERE id = ?",
            [$messageId]
        );

        if ($row === null) {
            return false;
        }

        if ((int) $row['len'] <= 1) {
            return $this->db->delete("DELETE FROM messages WHERE id = ?", [$messageId]) > 0;
        }

        return $this->db->update(
            "UPDATE messages SET categorized = categorized - ?, updated_at = NOW() WHERE id = ?",
            [$arrayIndex, $messageId]
        ) > 0;
    }

    public function getDistinctCategories(int $chatId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT (t.value->>'category') AS category
            FROM messages m
            CROSS JOIN LATERAL jsonb_array_elements(m.categorized) AS t(value)
            WHERE m.chat_id = ? AND m.categorized IS NOT NULL
                AND t.value->>'type' IN ('income', 'expense')
                AND t.value->>'category' IS NOT NULL
            ORDER BY category
        SQL;

        return array_column($this->db->query($sql, [$chatId]), 'category');
    }

    public function getDistinctWallets(int $chatId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT (t.value->>'wallet') AS wallet
            FROM messages m
            CROSS JOIN LATERAL jsonb_array_elements(m.categorized) AS t(value)
            WHERE m.chat_id = ? AND m.categorized IS NOT NULL
                AND t.value->>'type' = 'balance'
                AND t.value->>'wallet' IS NOT NULL
            ORDER BY wallet
        SQL;

        return array_column($this->db->query($sql, [$chatId]), 'wallet');
    }
}
