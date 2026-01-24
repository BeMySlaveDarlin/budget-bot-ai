<?php

declare(strict_types=1);

namespace App\Service\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    public function __construct(
        public string $path,
        public string|array $methods = 'GET',
        public ?string $name = null,
    ) {
    }

    public function getMethods(): array
    {
        return is_array($this->methods) ? $this->methods : [$this->methods];
    }
}
