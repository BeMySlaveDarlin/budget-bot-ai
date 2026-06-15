<?php

declare(strict_types=1);

namespace App\Application\Meals\Repository;

use App\Service\Database\DatabaseConnection;
use App\Service\Database\DatabaseException;
use DI\Attribute\Injectable;
use PDO;
use RuntimeException;

#[Injectable]
final class MealFactConflictRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function getPending(int $chatId): array
    {
        return $this->db->query(
            "SELECT c.id, c.old_fact_id, c.proposed_fact, c.recommendation,
                    c.source_note, c.status, c.created_at, f.fact AS old_fact_text
             FROM meal_fact_conflicts c
             LEFT JOIN meal_facts f ON f.id = c.old_fact_id
             WHERE c.chat_id = ? AND c.status = 'pending'
             ORDER BY c.created_at DESC",
            [$chatId]
        );
    }

    public function findById(int $id, int $chatId): ?array
    {
        return $this->db->queryFirst(
            "SELECT id, chat_id, old_fact_id, proposed_fact, recommendation, status
             FROM meal_fact_conflicts
             WHERE id = ? AND chat_id = ?",
            [$id, $chatId]
        );
    }

    public function resolve(int $id, int $chatId, string $action): bool
    {
        $conflict = $this->findById($id, $chatId);
        if ($conflict === null || $conflict['status'] !== 'pending') {
            return false;
        }

        try {
            return (bool) $this->db->transaction(
                fn(PDO $pdo): bool => $this->applyResolution($pdo, $conflict, $chatId, $action)
            );
        } catch (DatabaseException) {
            return false;
        }
    }

    private function applyResolution(PDO $pdo, array $conflict, int $chatId, string $action): bool
    {
        if ($action === 'reject') {
            $this->markRejected($pdo, (int) $conflict['id'], $chatId);

            return true;
        }

        $newId = $this->insertProposedFact($pdo, $chatId, (string) $conflict['proposed_fact']);

        if ($action === 'accept_new' && $conflict['old_fact_id'] !== null) {
            $this->supersedeOldFact($pdo, (int) $conflict['old_fact_id'], $chatId, $newId);
        }

        $this->markAccepted($pdo, (int) $conflict['id'], $chatId);

        return true;
    }

    private function insertProposedFact(PDO $pdo, int $chatId, string $fact): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO meal_facts (chat_id, fact, source, is_active, created_at, updated_at)
             VALUES (?, ?, 'extracted', TRUE, NOW(), NOW())
             RETURNING id"
        );
        $stmt->execute([$chatId, $fact]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['id'] ?? 0);
    }

    private function supersedeOldFact(PDO $pdo, int $oldFactId, int $chatId, int $newId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE meal_facts
             SET is_active = FALSE, superseded_by = ?, updated_at = NOW()
             WHERE id = ? AND chat_id = ?"
        );
        $stmt->execute([$newId, $oldFactId, $chatId]);
    }

    private function markAccepted(PDO $pdo, int $conflictId, int $chatId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE meal_fact_conflicts
             SET status = 'accepted', resolved_at = NOW()
             WHERE id = ? AND chat_id = ? AND status = 'pending'"
        );
        $stmt->execute([$conflictId, $chatId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Conflict already resolved');
        }
    }

    private function markRejected(PDO $pdo, int $conflictId, int $chatId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE meal_fact_conflicts
             SET status = 'rejected', resolved_at = NOW()
             WHERE id = ? AND chat_id = ? AND status = 'pending'"
        );
        $stmt->execute([$conflictId, $chatId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Conflict already resolved');
        }
    }
}
