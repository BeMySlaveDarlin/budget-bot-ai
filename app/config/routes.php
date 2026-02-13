<?php

declare(strict_types=1);

use App\Application\Budget\Http\Handler\WebhookHandler;
use App\Application\Report\Http\Handler\ReportApiHandler;
use App\Application\Report\Http\Handler\ReportPageHandler;
use App\Application\System\Http\Handler\HealthHandler;

return [
    'handlers' => [
        HealthHandler::class,
        WebhookHandler::class,
        ReportPageHandler::class,
        ReportApiHandler::class,
    ],
    'directories' => [],
];
