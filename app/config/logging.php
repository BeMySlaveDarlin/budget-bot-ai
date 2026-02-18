<?php

declare(strict_types=1);

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

$logLevel = strtolower($_ENV['LOG_LEVEL'] ?? 'info');
$level = match ($logLevel) {
    'debug' => 'debug',
    'info' => 'info',
    'warning', 'warn' => 'warning',
    'error' => 'error',
    default => 'info',
};

return [
    'default_channel' => 'app',

    'channels' => [
        'app' => [
            'handlers' => [
                [
                    'class' => RotatingFileHandler::class,
                    'path' => '/var/www/app/runtime/logs/app.log',
                    'level' => $level,
                    'max_files' => 14,
                    'formatter' => 'json',
                ],
                [
                    'class' => StreamHandler::class,
                    'stream' => 'php://stdout',
                    'level' => $level,
                    'formatter' => 'line',
                ],
            ],
        ],
        'telegram' => [
            'handlers' => [
                [
                    'class' => RotatingFileHandler::class,
                    'path' => '/var/www/app/runtime/logs/telegram.log',
                    'level' => 'debug',
                    'max_files' => 7,
                    'formatter' => 'json',
                ],
            ],
        ],
        'llm' => [
            'handlers' => [
                [
                    'class' => RotatingFileHandler::class,
                    'path' => '/var/www/app/runtime/logs/llm.log',
                    'level' => 'debug',
                    'max_files' => 7,
                    'formatter' => 'json',
                ],
            ],
        ],
        'http' => [
            'handlers' => [
                [
                    'class' => RotatingFileHandler::class,
                    'path' => '/var/www/app/runtime/logs/http.log',
                    'level' => $level,
                    'max_files' => 7,
                    'formatter' => 'json',
                ],
                [
                    'class' => StreamHandler::class,
                    'stream' => 'php://stdout',
                    'level' => $level,
                    'formatter' => 'line',
                ],
            ],
        ],
        'error' => [
            'handlers' => [
                [
                    'class' => RotatingFileHandler::class,
                    'path' => '/var/www/app/runtime/logs/error.log',
                    'level' => 'error',
                    'max_files' => 14,
                    'formatter' => 'json',
                ],
                [
                    'class' => StreamHandler::class,
                    'stream' => 'php://stderr',
                    'level' => 'error',
                    'formatter' => 'line',
                ],
            ],
        ],
    ],

    'formatters' => [
        'json' => [
            'include_stacktraces' => true,
            'append_newline' => true,
        ],
        'line' => [
            'format' => "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'date_format' => 'Y-m-d H:i:s',
            'allow_inline_line_breaks' => true,
        ],
    ],

    'processors' => [
        'memory' => [],
        'process_id' => [],
    ],
];
