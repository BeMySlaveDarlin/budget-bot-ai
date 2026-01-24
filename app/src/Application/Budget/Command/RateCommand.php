<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Budget\Command\Attribute\BotCommand;
use App\Application\Budget\Command\Contract\BotCommandInterface;
use App\Application\Budget\DTO\CommandContext;
use App\Component\ExchangeRate\ExchangeRateService;
use DI\Attribute\Injectable;

#[Injectable]
#[BotCommand(command: 'rate', description: 'Курсы валют')]
class RateCommand implements BotCommandInterface
{
    public function __construct(
        private ExchangeRateService $exchangeRateService
    ) {
    }

    public function execute(CommandContext $ctx): ?string
    {
        return $this->exchangeRateService->formatRatesForDisplay($ctx->getCurrency());
    }

    public function getKeyboard(CommandContext $ctx): ?array
    {
        return null;
    }
}
