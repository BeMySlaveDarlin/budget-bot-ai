<?php

declare(strict_types=1);

return [
    'bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
    'webhook_secret' => $_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '',
    'api_url' => 'https://api.telegram.org',

    'parse_mode' => 'Markdown',
    'disable_web_page_preview' => true,

    'commands' => [
        'start' => 'Начать работу с ботом',
        'stats' => 'Статистика за период',
        'ai' => 'Задать вопрос AI о бюджете',
    ],
];
