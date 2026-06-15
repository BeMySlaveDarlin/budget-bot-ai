<?php

declare(strict_types=1);

namespace App\Application\Meals\DTO;

readonly class MealReply
{
    public function __construct(
        public ?string $text,
        public bool $openFridge = false
    ) {
    }

    public static function text(?string $text): self
    {
        return new self($text, false);
    }

    public static function fridge(?string $text = null): self
    {
        return new self($text, true);
    }
}
