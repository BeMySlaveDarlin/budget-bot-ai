<?php

declare(strict_types=1);

namespace App\Service\Database\Dto;

interface DtoInterface
{
    public static function fromArray(array $data): static;
    public function toArray(): array;
}
