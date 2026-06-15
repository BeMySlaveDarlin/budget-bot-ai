<?php

declare(strict_types=1);

return [
    'budget_bot_token' => $_ENV['BUDGET_BOT_TOKEN'] ?? $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
    'meals_bot_token' => $_ENV['MEALS_BOT_TOKEN'] ?? '',
    'webhook_secret' => $_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '',
    'api_url' => 'https://api.telegram.org',
    'dry_run' => filter_var($_ENV['TELEGRAM_DRY_RUN'] ?? false, FILTER_VALIDATE_BOOLEAN),

    'parse_mode' => 'Markdown',
    'disable_web_page_preview' => true,

    'commands' => [
        'start' => 'Начать работу с ботом',
        'stats' => 'Статистика за период',
        'ai' => 'Задать вопрос AI о бюджете',
    ],
];
