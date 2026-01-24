<?php

declare(strict_types=1);

use App\Service\Config\ConfigFactory;
use App\Service\Container\ContainerFactory;
use App\Service\Console\CommandResolver;
use Dotenv\Dotenv;
use Swoole\Coroutine;

require_once __DIR__ . '/../vendor/autoload.php';

Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

try {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();

    $environment = $_ENV['APP_ENV'] ?? 'dev';
    $configPath = dirname(__DIR__) . '/config';

    $configFactory = new ConfigFactory($configPath, $environment);
    $config = $configFactory->create();

    $containerFactory = new ContainerFactory($config);
    $container = $containerFactory->create();

    $resolver = new CommandResolver($container);

    $directories = [
        __DIR__ . '/../src/Service/Console/Handler',
    ];
    $resolver->registerFromDirectories($directories);

    $commandName = $argv[1] ?? 'help';
    $commandArgs = array_slice($argv, 2);

    exit($resolver->execute($commandName, $commandArgs));
} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    echo "File: {$e->getFile()}:{$e->getLine()}\n";
    exit(1);
}
