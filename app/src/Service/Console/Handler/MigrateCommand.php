<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;
use App\Service\Database\DatabaseConnection;

#[Command(name: 'migrate', description: 'Run database migrations')]
class MigrateCommand implements CommandInterface
{
    private const string MIGRATIONS_PATH = __DIR__ . '/../../../../database/Migrations';

    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function execute(array $args = []): int
    {
        $action = $args[0] ?? 'up';

        return match ($action) {
            '--fresh' => $this->fresh(),
            '--status' => $this->status(),
            '--down' => $this->down(),
            default => $this->up(),
        };
    }

    private function up(): int
    {
        $this->ensureMigrationsTable();
        $executed = $this->getExecutedMigrations();
        $migrations = $this->getMigrations();

        $count = 0;
        foreach ($migrations as $migration => $class) {
            if (in_array($migration, $executed)) {
                continue;
            }

            echo "Running: {$migration}\n";
            $instance = new $class($this->db);
            $instance->up();

            $this->db->execute(
                "INSERT INTO migrations (migration, batch) VALUES (?, ?)",
                [$migration, time()]
            );
            $count++;
        }

        if ($count === 0) {
            echo "Nothing to migrate.\n";
        } else {
            echo "Migrated {$count} migration(s).\n";
        }

        return 0;
    }

    private function down(): int
    {
        $this->ensureMigrationsTable();
        $executed = $this->getExecutedMigrations();
        $migrations = array_reverse($this->getMigrations());

        if (empty($executed)) {
            echo "Nothing to rollback.\n";
            return 0;
        }

        $lastMigration = end($executed);
        $class = $migrations[$lastMigration] ?? null;

        if ($class) {
            echo "Rolling back: {$lastMigration}\n";
            $instance = new $class($this->db);
            $instance->down();

            $this->db->execute("DELETE FROM migrations WHERE migration = ?", [$lastMigration]);
            echo "Rolled back: {$lastMigration}\n";
        }

        return 0;
    }

    private function fresh(): int
    {
        echo "Dropping all tables...\n";

        $tables = $this->db->query("
            SELECT tablename FROM pg_tables
            WHERE schemaname = 'public'
        ");

        foreach ($tables as $table) {
            $this->db->execute("DROP TABLE IF EXISTS \"{$table['tablename']}\" CASCADE");
        }

        echo "Running all migrations...\n";
        return $this->up();
    }

    private function status(): int
    {
        $this->ensureMigrationsTable();
        $executed = $this->getExecutedMigrations();
        $migrations = $this->getMigrations();

        echo "Migration Status:\n";
        echo str_repeat('-', 60) . "\n";

        foreach ($migrations as $migration => $class) {
            $status = in_array($migration, $executed) ? '[✓]' : '[ ]';
            echo "{$status} {$migration}\n";
        }

        return 0;
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS migrations (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    private function getExecutedMigrations(): array
    {
        $result = $this->db->query("SELECT migration FROM migrations ORDER BY id");
        return array_column($result, 'migration');
    }

    private function getMigrations(): array
    {
        $migrations = [];
        $path = realpath(self::MIGRATIONS_PATH);

        if (!$path || !is_dir($path)) {
            return $migrations;
        }

        $files = glob($path . '/*.php');
        sort($files);

        foreach ($files as $file) {
            $filename = basename($file, '.php');
            if ($filename === 'MigrationInterface') {
                continue;
            }

            require_once $file;

            $classNamePart = preg_replace('/^\d+_/', '', $filename);
            $className = 'Database\\Migrations\\' . $classNamePart;
            if (class_exists($className)) {
                $migrations[$filename] = $className;
            }
        }

        return $migrations;
    }

    public function getName(): string
    {
        return 'migrate';
    }

    public function getDescription(): string
    {
        return 'Run database migrations';
    }
}
