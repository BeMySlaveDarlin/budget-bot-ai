<?php

declare(strict_types=1);

return [
    'host' => $_ENV['DB_HOST'] ?? 'database',
    'port' => (int)($_ENV['DB_PORT'] ?? 5432),
    'database' => $_ENV['DB_DATABASE'] ?? 'budget_db',
    'username' => $_ENV['DB_USERNAME'] ?? 'budget_user',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'pool_size' => (int)($_ENV['DB_POOL_MAX'] ?? 10),
    'pool_min' => (int)($_ENV['DB_POOL_MIN'] ?? 2),
];
