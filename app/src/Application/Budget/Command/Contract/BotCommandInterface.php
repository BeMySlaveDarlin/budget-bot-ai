<?php

declare(strict_types=1);

namespace App\Application\Budget\Command\Contract;

use App\Application\Budget\DTO\CommandContext;

interface BotCommandInterface
{
    public function execute(CommandContext $ctx): ?string;

    public function getKeyboard(CommandContext $ctx): ?array;
}
