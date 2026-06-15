<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Service\Config\ConfigFactory;
use App\Service\Container\ContainerFactory;
use DI\Container;

final class TestContainerFactory
{
    public static function create(): Container
    {
        $configPath = dirname(__DIR__, 2) . '/config';
        $environment = $_ENV['APP_ENV'] ?? 'dev';

        $config = new ConfigFactory($configPath, $environment)->create();

        return new ContainerFactory($config)->create();
    }
}
