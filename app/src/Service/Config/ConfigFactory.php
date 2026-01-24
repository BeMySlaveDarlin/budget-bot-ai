<?php

declare(strict_types=1);

namespace App\Service\Config;

class ConfigFactory
{
    public function __construct(
        private string $configPath,
        private string $environment = 'dev'
    ) {}

    public function create(): Config
    {
        $config = new Config($this->configPath, $this->environment);
        $config->load();
        return $config;
    }
}
