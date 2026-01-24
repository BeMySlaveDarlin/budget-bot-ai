<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Budget\Command\Attribute\BotCommand;
use App\Application\Budget\Command\Contract\BotCommandInterface;
use App\Application\Budget\DTO\CommandContext;
use App\Service\Settings\Repository\SettingsRepository;
use DI\Attribute\Injectable;

#[Injectable]
#[BotCommand(command: 'help', description: 'Справка по командам', enabledOnly: false)]
class HelpCommand implements BotCommandInterface
{
    public function __construct(
        private SettingsRepository $settingsRepo
    ) {
    }

    public function execute(CommandContext $ctx): ?string
    {
        $text = $this->settingsRepo->get('bot.messages.help');

        if ($text) {
            return $text;
        }

        $help = "<b>Команды бота:</b>\n\n";
        $help .= "/start - начать работу с ботом\n";
        $help .= "/stats [N] - статистика за N месяцев (1-12)\n";
        $help .= "/ai вопрос [N] - AI анализ расходов за N месяцев (1-12)\n";
        $help .= "/rate - текущие курсы валют\n";
        $help .= "/status - диагностика системы\n";

        if ($ctx->isAdmin) {
            $help .= "/settings - настройки чата\n";
        }

        $help .= "\n<b>Формат записей:</b>\n";
        $help .= "• 1500 обед\n";
        $help .= "• 50000 зарплата\n";
        $help .= "• 100 USD подарок";

        return $help;
    }

    public function getKeyboard(CommandContext $ctx): ?array
    {
        return null;
    }
}
