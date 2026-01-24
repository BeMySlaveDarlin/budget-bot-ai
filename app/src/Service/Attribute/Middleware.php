<?php

declare(strict_types=1);

namespace App\Service\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Middleware
{
    public function __construct(
        public int $priority = 0,
    ) {
    }
}
