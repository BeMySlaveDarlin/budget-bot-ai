<?php

declare(strict_types=1);

namespace App\Service\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Command
{
    public function __construct(
        public string $name,
        public string $description = '',
    ) {
    }
}
