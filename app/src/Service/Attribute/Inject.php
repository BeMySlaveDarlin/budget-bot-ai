<?php

declare(strict_types=1);

namespace App\Service\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Inject
{
    public function __construct(
        public ?string $name = null,
    ) {
    }
}
