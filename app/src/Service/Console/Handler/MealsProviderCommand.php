<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Application\Meals\Repository\BotConfigRepository;
use App\Component\LLM\LLMClientFactory;
use App\Service\Attribute\Command;
use App\Service\Config\Config;
use App\Service\Console\Contract\CommandInterface;

#[Command(name: 'meals:provider', description: 'Resolve and ping the LLM provider bound to a bot')]
final class MealsProviderCommand implements CommandInterface
{
    public function __construct(
        private BotConfigRepository $botConfig,
        private LLMClientFactory $factory,
        private Config $config
    ) {
    }

    public function execute(array $args = []): int
    {
        $botCode = $args[0] ?? 'meals';
        $provider = $this->botConfig->resolveProvider($botCode);

        if ($provider !== null) {
            echo "bot={$botCode} -> provider={$provider['code']} (id={$provider['id']}, model={$provider['model_name']}) [bound]\n";
            $client = $this->factory->createById((int) $provider['id']);
        } else {
            $default = $this->config->get('llm.default_provider', 'claude');
            echo "bot={$botCode} -> no active binding, fallback to default={$default}\n";
            $client = $this->factory->createByCode($default);
        }

        $ping = $client->ping();

        if (($ping['ok'] ?? false) === true) {
            echo "ping OK: model={$ping['model']}, '{$ping['question']}' -> '{$ping['response']}' ({$ping['latency_ms']}ms)\n";
            return 0;
        }

        echo "ping FAILED: {$ping['error']}\n";

        return 1;
    }

    public function getName(): string
    {
        return 'meals:provider';
    }

    public function getDescription(): string
    {
        return 'Resolve and ping the LLM provider bound to a bot';
    }
}
