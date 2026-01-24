<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Budget\Command\Attribute\BotCommand;
use App\Application\Budget\Command\Contract\BotCommandInterface;
use App\Application\Budget\DTO\CommandContext;
use App\Service\Settings\Repository\SettingsRepository;
use DI\Attribute\Injectable;

#[Injectable]
#[BotCommand(command: 'start', description: 'Начать работу с ботом', enabledOnly: false)]
class StartCommand implements BotCommandInterface
{
    public function __construct(
        private SettingsRepository $settingsRepo
    ) {
    }

    public function execute(CommandContext $ctx): ?string
    {
        $text = $this->settingsRepo->get('bot.messages.start');

        if ($text) {
            return $text;
        }

        return <<<HTML
<b>Привет!</b> Я бот для учёта бюджета.

Просто пиши расходы/доходы:
• 1500 обед
• 50000 зарплата
• 100 USD подарок

<b>Команды:</b>
/stats [1-12] - статистика за N мес.
/ai [вопрос] - AI анализ
/rate - курсы валют
/status - диагностика системы
/help - справка
HTML;
    }

    public function getKeyboard(CommandContext $ctx): ?array
    {
        return null;
    }
}
