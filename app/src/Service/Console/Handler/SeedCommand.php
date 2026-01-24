<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;
use App\Service\Database\DatabaseConnection;

#[Command(name: 'seed', description: 'Run database seeders')]
class SeedCommand implements CommandInterface
{
    private const string SEEDERS_PATH = __DIR__ . '/../../../../database/Seeders';

    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function execute(array $args = []): int
    {
        $specific = $args[0] ?? null;
        $seeders = $this->getSeeders();

        if (empty($seeders)) {
            echo "No seeders found.\n";
            return 0;
        }

        foreach ($seeders as $name => $class) {
            if ($specific && $name !== $specific) {
                continue;
            }

            echo "Seeding: {$name}\n";
            $instance = new $class($this->db);
            $instance->run();
            echo "Seeded: {$name}\n";
        }

        return 0;
    }

    private function getSeeders(): array
    {
        $seeders = [];
        $path = realpath(self::SEEDERS_PATH);

        if (!$path || !is_dir($path)) {
            return $seeders;
        }

        $files = glob($path . '/*.php');
        sort($files);

        foreach ($files as $file) {
            $filename = basename($file, '.php');
            if ($filename === 'SeederInterface') {
                continue;
            }

            require_once $file;
            $className = 'Database\\Seeders\\' . $filename;
            if (class_exists($className)) {
                $seeders[$filename] = $className;
            }
        }

        return $seeders;
    }

    public function getName(): string
    {
        return 'seed';
    }

    public function getDescription(): string
    {
        return 'Run database seeders';
    }
}
