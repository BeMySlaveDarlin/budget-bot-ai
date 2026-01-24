<?php

declare(strict_types=1);

namespace App\Service\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class SwooleEventHandler
{
    public function __construct(
        public readonly string $event,
    ) {
    }
}
