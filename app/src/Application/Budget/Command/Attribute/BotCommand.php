<?php

declare(strict_types=1);

namespace App\Application\Budget\Command\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class BotCommand
{
    public function __construct(
        public string $command,
        public string $description = '',
        public bool $adminOnly = false,
        public bool $enabledOnly = true,
        public bool $showPending = false,
        public string $pendingMessage = '⏳ Обрабатываю...'
    ) {
    }
}
