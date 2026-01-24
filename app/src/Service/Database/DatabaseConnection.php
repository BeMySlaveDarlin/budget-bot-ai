<?php

declare(strict_types=1);

namespace App\Service\Database;

use App\Service\Config\Config;
use DI\Attribute\Injectable;
use PDO;
use Psr\Log\LoggerInterface;
use PDOStatement;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOProxy;
use Throwable;

#[Injectable]
final class DatabaseConnection
{
    private ?PDOPool $pool = null;
    private array $config;
    private int $poolSize;

    public function __construct(
        private Config $configuration,
        private LoggerInterface $logger
    ) {
        $this->config = $this->configuration->get('database', []);
        $this->poolSize = $this->config['pool_size'] ?? 20;
        $this->initializePool();
    }

    public function execute(string $sql, array $params = []): PDOStatement
    {
        $pdo = $this->getConnection();

        try {
            $stmt = $pdo->prepare($sql);
            $this->bindTypedParameters($stmt, $params);
            $stmt->execute();

            return $stmt;
        } catch (Throwable $e) {
            throw new DatabaseException("Query execution failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    private function bindTypedParameters(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $paramName = is_int($key) ? $key + 1 : $key;

            if (is_bool($value)) {
                $stmt->bindValue($paramName, $value, PDO::PARAM_BOOL);
            } elseif (is_int($value)) {
                $stmt->bindValue($paramName, $value, PDO::PARAM_INT);
            } elseif (is_null($value)) {
                $stmt->bindValue($paramName, $value, PDO::PARAM_NULL);
            } elseif (is_array($value)) {
                $stmt->bindValue($paramName, json_encode($value), PDO::PARAM_STR);
            } else {
                $stmt->bindValue($paramName, $value, PDO::PARAM_STR);
            }
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $pdo = $this->getConnection();

        try {
            $stmt = $pdo->prepare($sql);
            $this->bindTypedParameters($stmt, $params);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new DatabaseException("Query execution failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    public function queryFirst(string $sql, array $params = []): ?array
    {
        $pdo = $this->getConnection();

        try {
            $stmt = $pdo->prepare($sql);
            $this->bindTypedParameters($stmt, $params);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result === false ? null : $result;
        } catch (Throwable $e) {
            throw new DatabaseException("Query execution failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    public function insert(string $sql, array $params = []): int
    {
        $pdo = $this->getConnection();

        try {
            if (stripos($sql, 'RETURNING') === false && stripos($sql, 'INSERT') === 0) {
                $sql .= ' RETURNING id';
            }

            $stmt = $pdo->prepare($sql);
            $this->bindTypedParameters($stmt, $params);
            $stmt->execute();

            if (stripos($sql, 'RETURNING') !== false) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return (int) ($result['id'] ?? 0);
            }

            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            throw new DatabaseException("Insert failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    public function update(string $sql, array $params = []): int
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->rowCount();
    }

    public function delete(string $sql, array $params = []): int
    {
        return $this->update($sql, $params);
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->getConnection();

        try {
            $pdo->beginTransaction();
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new DatabaseException("Transaction failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    public function queryColumn(string $sql, array $params = [], int $columnIndex = 0): mixed
    {
        $pdo = $this->getConnection();

        try {
            $stmt = $pdo->prepare($sql);
            $this->bindTypedParameters($stmt, $params);
            $stmt->execute();

            return $stmt->fetchColumn($columnIndex);
        } catch (Throwable $e) {
            throw new DatabaseException("Query execution failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    public function queryWithFetchMode(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): array
    {
        $pdo = $this->getConnection();

        try {
            $stmt = $pdo->prepare($sql);
            $this->bindTypedParameters($stmt, $params);
            $stmt->execute();

            return $stmt->fetchAll($fetchMode);
        } catch (Throwable $e) {
            throw new DatabaseException("Query execution failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    public function queryObjects(string $sql, array $params = [], ?string $className = null): array
    {
        $fetchMode = $className ? PDO::FETCH_CLASS : PDO::FETCH_OBJ;
        $pdo = $this->getConnection();

        try {
            $stmt = $pdo->prepare($sql);
            $this->bindTypedParameters($stmt, $params);
            $stmt->execute();

            if ($className) {
                $stmt->setFetchMode(PDO::FETCH_CLASS, $className);
            } else {
                $stmt->setFetchMode(PDO::FETCH_OBJ);
            }

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            throw new DatabaseException("Query execution failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    public function getRowCount(string $sql, array $params = []): int
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->rowCount();
    }

    public function exists(string $sql, array $params = []): bool
    {
        return $this->queryColumn($sql, $params) !== false;
    }

    public function getStats(): array
    {
        return [
            'pool_enabled' => $this->pool !== null,
            'pool_size' => $this->poolSize,
            'is_swoole_context' => function_exists('Swoole\Coroutine\getcid') && \Swoole\Coroutine\getcid() >= 0,
        ];
    }

    private function getConnection(): PDO|PDOProxy
    {
        if ($this->pool && function_exists('Swoole\Coroutine\getcid') && \Swoole\Coroutine\getcid() >= 0) {
            return $this->pool->get();
        }

        return $this->createDirectConnection();
    }

    private function releaseConnection(PDO|PDOProxy $pdo): void
    {
        if ($this->pool && function_exists('Swoole\Coroutine\getcid') && \Swoole\Coroutine\getcid() >= 0 && $pdo instanceof PDOProxy) {
            $this->pool->put($pdo);
        }
    }

    private function createDirectConnection(): PDO
    {
        $host = $this->config['host'] ?? 'database';
        $port = $this->config['port'] ?? 5432;
        $database = $this->config['database'] ?? '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        return new PDO(
            $dsn,
            $this->config['username'] ?? '',
            $this->config['password'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private function initializePool(): void
    {
        if (!class_exists(PDOPool::class)) {
            return;
        }

        try {
            $config = new PDOConfig();
            $config
                ->withDriver('pgsql')
                ->withHost($this->config['host'] ?? 'database')
                ->withPort($this->config['port'] ?? 5432)
                ->withDbname($this->config['database'] ?? '')
                ->withUsername($this->config['username'] ?? '')
                ->withPassword($this->config['password'] ?? '');

            $config->withOptions([
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $this->pool = new PDOPool($config, $this->poolSize);
        } catch (Throwable $e) {
            $this->logger->error('PDO Pool initialization failed', ['error' => $e->getMessage()]);
            $this->pool = null;
        }
    }

    public function closePool(): void
    {
        if ($this->pool) {
            $this->pool->close();
            $this->pool = null;
        }
    }
}
