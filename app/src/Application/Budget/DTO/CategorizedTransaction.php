<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

readonly class CategorizedTransaction
{
    public function __construct(
        public string $type,
        public string $category,
        public float $amount,
        public string $currency,
        public string $description
    ) {
    }

    public static function fromArray(array $data, string $type): self
    {
        return new self(
            $type,
            $data['category'] ?? 'другое',
            (float) ($data['amount'] ?? 0),
            $data['currency'] ?? 'THB',
            $data['description'] ?? ''
        );
    }
}
