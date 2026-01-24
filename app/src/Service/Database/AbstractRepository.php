<?php

declare(strict_types=1);

namespace App\Service\Database;

use App\Service\Database\Dto\DtoInterface;

abstract class AbstractRepository
{
    private ?string $validatedTableName = null;

    public function __construct(
        protected DatabaseConnection $connection
    ) {
    }

    abstract protected function getTableName(): string;
    abstract protected function getDtoClass(): string;

    protected function getSafeTableName(): string
    {
        if ($this->validatedTableName === null) {
            $tableName = $this->getTableName();
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
                throw new \InvalidArgumentException("Invalid table name: {$tableName}");
            }
            $this->validatedTableName = $tableName;
        }

        return $this->validatedTableName;
    }

    public function find(int $id): ?DtoInterface
    {
        $sql = "SELECT * FROM {$this->getSafeTableName()} WHERE id = ?";
        $data = $this->connection->queryFirst($sql, [$id]);

        return $data ? $this->createDto($data) : null;
    }

    public function findBy(array $criteria, ?int $limit = null, ?int $offset = null): array
    {
        $sql = "SELECT * FROM {$this->getSafeTableName()}";
        $params = [];

        if (!empty($criteria)) {
            $conditions = [];
            foreach ($criteria as $field => $value) {
                $conditions[] = "{$field} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        if ($limit !== null) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
            if ($offset !== null) {
                $sql .= " OFFSET ?";
                $params[] = $offset;
            }
        }

        $results = $this->connection->query($sql, $params);

        return array_map(fn($data) => $this->createDto($data), $results);
    }

    public function findOneBy(array $criteria): ?DtoInterface
    {
        $results = $this->findBy($criteria, 1);
        return $results[0] ?? null;
    }

    public function create(array $data): int
    {
        $fields = array_keys($data);
        $placeholders = str_repeat('?,', count($fields) - 1) . '?';

        $sql = "INSERT INTO {$this->getSafeTableName()} (" . implode(', ', $fields) . ") VALUES ({$placeholders})";

        return $this->connection->insert($sql, array_values($data));
    }

    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $fields = array_keys($data);
        $assignments = array_map(fn($field) => "{$field} = ?", $fields);

        $sql = "UPDATE {$this->getSafeTableName()} SET " . implode(', ', $assignments) . " WHERE id = ?";
        $params = array_merge(array_values($data), [$id]);

        return $this->connection->update($sql, $params) > 0;
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM {$this->getSafeTableName()} WHERE id = ?";
        return $this->connection->delete($sql, [$id]) > 0;
    }

    public function count(array $criteria = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->getSafeTableName()}";
        $params = [];

        if (!empty($criteria)) {
            $conditions = [];
            foreach ($criteria as $field => $value) {
                $conditions[] = "{$field} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $result = $this->connection->queryFirst($sql, $params);
        return (int) ($result['count'] ?? 0);
    }

    protected function createDto(array $data): DtoInterface
    {
        $dtoClass = $this->getDtoClass();
        return $dtoClass::fromArray($data);
    }
}
