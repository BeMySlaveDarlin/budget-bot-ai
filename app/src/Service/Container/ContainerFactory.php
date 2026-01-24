<?php

declare(strict_types=1);

namespace App\Service\Container;

use App\Service\Config\Config;
use DI\Container;
use DI\ContainerBuilder;

class ContainerFactory
{
    public function __construct(
        private Config $config
    ) {}

    public function create(): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAttributes(true);
        $this->registerServices($builder);

        return $builder->build();
    }

    private function registerServices(ContainerBuilder $builder): void
    {
        $containerConfig = $this->config->get('container', []);

        $definitions = [
            Config::class => fn() => $this->config,
            ...$containerConfig,
        ];

        $builder->addDefinitions($definitions);
    }
}
