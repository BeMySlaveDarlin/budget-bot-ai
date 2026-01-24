<?php

declare(strict_types=1);

namespace App\Service\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class BotCommand
{
    public function __construct(
        public string $command,
        public string $description = '',
    ) {
    }
}
