<?php

declare(strict_types=1);

use App\Application\Budget\Http\Handler\WebhookHandler as BudgetWebhookHandler;
use App\Application\Meals\Http\Handler\MealAppApiHandler;
use App\Application\Meals\Http\Handler\MealAppPageHandler;
use App\Application\Meals\Http\Handler\WebhookHandler as MealsWebhookHandler;
use App\Application\Report\Http\Handler\ReportApiHandler;
use App\Application\Report\Http\Handler\ReportPageHandler;
use App\Application\System\Http\Handler\HealthHandler;

return [
    'handlers' => [
        HealthHandler::class,
        BudgetWebhookHandler::class,
        MealsWebhookHandler::class,
        ReportPageHandler::class,
        ReportApiHandler::class,
        MealAppPageHandler::class,
        MealAppApiHandler::class,
    ],
    'directories' => [],
];
