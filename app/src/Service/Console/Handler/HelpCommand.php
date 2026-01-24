<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Service\Attribute\Command;
use App\Service\Console\CommandCollector;
use App\Service\Console\Contract\CommandInterface;

#[Command(name: 'help', description: 'Show available commands')]
class HelpCommand implements CommandInterface
{
    public function __construct(
        private CommandCollector $commandCollector,
    ) {
    }

    public function execute(array $args = []): int
    {
        $directories = [__DIR__];
        $commands = $this->commandCollector->collectFromDirectories($directories);

        echo "\nBudget Bot CLI\n";
        echo str_repeat('=', 50) . "\n\n";
        echo "Available commands:\n\n";

        $maxLen = 0;
        foreach ($commands as $command) {
            $maxLen = max($maxLen, strlen($command['name']));
        }

        foreach ($commands as $command) {
            $name = str_pad($command['name'], $maxLen + 2);
            $description = $command['description'] ?: 'No description';
            echo "  {$name} {$description}\n";
        }

        echo "\nUsage: php bin/cli.php <command> [arguments]\n\n";

        return 0;
    }

    public function getName(): string
    {
        return 'help';
    }

    public function getDescription(): string
    {
        return 'Show available commands';
    }
}
