<?php

declare(strict_types=1);

namespace App\Service\Http\Contract;

use App\Service\Http\Context\HttpContext;

interface ContextMiddlewareInterface
{
    public function process(HttpContext $context, ContextHandlerInterface $handler): void;
}
