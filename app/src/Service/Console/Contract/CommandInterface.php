<?php

declare(strict_types=1);

namespace App\Service\Console\Contract;

interface CommandInterface
{
    public function execute(array $args = []): int;
    public function getName(): string;
    public function getDescription(): string;
}
