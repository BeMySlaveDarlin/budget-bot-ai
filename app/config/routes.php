<?php

declare(strict_types=1);

use App\Application\Budget\Http\Handler\WebhookHandler;
use App\Application\System\Http\Handler\HealthHandler;

return [
    'handlers' => [
        HealthHandler::class,
        WebhookHandler::class,
    ],
    'directories' => [],
];
