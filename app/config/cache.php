<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'default_ttl' => 3600,

    'tiers' => [
        'l1' => [
            'enabled' => true,
            'max_size' => 1024,
            'max_value_size' => 65535,
        ],
        'l2' => [
            'enabled' => true,
            'host' => $_ENV['MEMCACHED_HOST'] ?? 'memcached',
            'port' => (int)($_ENV['MEMCACHED_PORT'] ?? 11211),
            'persistent_id' => 'budget_bot',
        ],
    ],

    'strategies' => [
        'read_through' => true,
        'write_through' => true,
    ],
];
