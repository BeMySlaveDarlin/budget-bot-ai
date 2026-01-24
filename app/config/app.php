<?php

declare(strict_types=1);

return [
    'name' => $_ENV['APP_NAME'] ?? 'budget-bot',
    'env' => $_ENV['APP_ENV'] ?? 'prod',
    'debug' => (bool)($_ENV['APP_DEBUG'] ?? false),
    'timezone' => $_ENV['TIMEZONE'] ?? 'Asia/Bangkok',
    'base_currency' => 'USD',
    'default_currency' => $_ENV['DEFAULT_CURRENCY'] ?? 'THB',
];
