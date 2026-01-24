<?php

declare(strict_types=1);

return [
    'api_key' => $_ENV['EXCHANGERATE_API_KEY'] ?? '',
    'api_url' => 'https://v6.exchangerate-api.com/v6',
    'update_interval' => 3600,
    'currencies' => ['USD', 'EUR', 'RUB', 'THB', 'CNY', 'JPY'],

    'coingecko' => [
        'api_key' => $_ENV['COINGECKO_API_KEY'] ?? '',
        'api_url' => 'https://api.coingecko.com/api/v3',
        'timeout' => 30,
        'currencies' => ['BTC', 'ETH', 'TRX', 'USDT'],
    ],
];
