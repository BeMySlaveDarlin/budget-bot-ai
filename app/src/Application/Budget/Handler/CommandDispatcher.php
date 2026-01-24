<?php

declare(strict_types=1);

namespace App\Application\Budget\Handler;

use App\Application\Budget\Command\AiCommand;
use App\Application\Budget\Command\Attribute\BotCommand;
use App\Application\Budget\Command\HelpCommand;
use App\Application\Budget\Command\RateCommand;
use App\Application\Budget\Command\SettingsCommand;
use App\Application\Budget\Command\StartCommand;
use App\Application\Budget\Command\StatsCommand;
use App\Application\Budget\Command\StatusCommand;
use App\Application\Budget\DTO\CommandContext;
use App\Component\Telegram\Repository\ChatUserRepository;
use App\Service\Settings\Repository\SettingsRepository;
use DI\Attribute\Injectable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

#[Injectable]
class CommandDispatcher
{
    private array $commands = [];
    private bool $initialized = false;

    public function __construct(
        private ContainerInterface $container,
        private ChatUserRepository $chatUserRepo,
        private SettingsRepository $settingsRepo,
        private LoggerInterface $logger
    ) {
    }

    public function dispatch(CommandContext $ctx): ?array
    {
        $this->ensureInitialized();

        $commandName = ltrim($ctx->command, '/');

        if (!isset($this->commands[$commandName])) {
            return null;
        }

        $meta = $this->commands[$commandName];

        if (!$this->checkPermission($meta, $ctx)) {
            $noPermissionMsg = $this->settingsRepo->get('bot.messages.no_permission');

            return [
                'text' => $noPermissionMsg ?? '❌ У вас нет прав для этого действия',
                'keyboard' => null,
            ];
        }

        try {
            $handler = $this->container->get($meta['class']);

            $text = $handler->execute($ctx);
            $keyboard = $handler->getKeyboard($ctx);

            return [
                'text' => $text,
                'keyboard' => $keyboard,
            ];
        } catch (\Throwable $e) {
            $this->logger->error("Command error: {$commandName}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'text' => '❌ Ошибка выполнения команды',
                'keyboard' => null,
            ];
        }
    }

    public function getRegisteredCommands(): array
    {
        $this->ensureInitialized();

        return $this->commands;
    }

    public function getCommandMeta(string $command): ?array
    {
        $this->ensureInitialized();
        $commandName = ltrim($command, '/');

        return $this->commands[$commandName] ?? null;
    }

    private function checkPermission(array $meta, CommandContext $ctx): bool
    {
        if ($meta['enabledOnly'] && !$ctx->isEnabled) {
            return false;
        }

        if ($meta['adminOnly'] && !$ctx->isAdmin) {
            return false;
        }

        return true;
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->scanCommands();
        $this->initialized = true;
    }

    private function scanCommands(): void
    {
        $commandClasses = [
            StartCommand::class,
            HelpCommand::class,
            StatsCommand::class,
            AiCommand::class,
            StatusCommand::class,
            RateCommand::class,
            SettingsCommand::class,
        ];

        foreach ($commandClasses as $class) {
            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(BotCommand::class);

            if (empty($attributes)) {
                continue;
            }

            $attr = $attributes[0]->newInstance();

            $this->commands[$attr->command] = [
                'class' => $class,
                'command' => $attr->command,
                'description' => $attr->description,
                'adminOnly' => $attr->adminOnly,
                'enabledOnly' => $attr->enabledOnly,
                'showPending' => $attr->showPending,
                'pendingMessage' => $attr->pendingMessage,
            ];
        }
    }
}
