<?php

declare(strict_types=1);

namespace App\Service\Http\Contract;

use App\Service\Http\Context\HttpContext;

interface ContextHandlerInterface
{
    public function handle(HttpContext $context): void;
}
