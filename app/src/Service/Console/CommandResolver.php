<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Service\Console\Contract\CommandInterface;
use DI\Container;

class CommandResolver
{
    private array $commandClasses = [];
    private array $commandInstances = [];

    public function __construct(
        private Container $container
    ) {
    }

    public function register(string $name, string $commandClass): void
    {
        $this->commandClasses[$name] = $commandClass;
    }

    public function registerFromDirectories(array $directories): void
    {
        $collector = $this->container->get(CommandCollector::class);
        $commands = $collector->collectFromDirectories($directories);

        foreach ($commands as $commandData) {
            $this->register($commandData['name'], $commandData['class']);
        }
    }

    public function resolve(string $name): ?CommandInterface
    {
        if (isset($this->commandInstances[$name])) {
            return $this->commandInstances[$name];
        }

        if (!isset($this->commandClasses[$name])) {
            return null;
        }

        $command = $this->container->get($this->commandClasses[$name]);

        if (!$command instanceof CommandInterface) {
            throw new \InvalidArgumentException(
                sprintf('Command %s must implement %s', $this->commandClasses[$name], CommandInterface::class)
            );
        }

        $this->commandInstances[$name] = $command;

        return $command;
    }

    public function execute(string $name, array $args = []): int
    {
        $command = $this->resolve($name);

        if ($command === null) {
            echo "Unknown command: {$name}\n";
            $this->resolve('help')?->execute();

            return 1;
        }

        return $command->execute($args);
    }

    public function getAvailableCommands(): array
    {
        return array_keys($this->commandClasses);
    }
}
