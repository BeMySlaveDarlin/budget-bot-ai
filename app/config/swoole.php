<?php

declare(strict_types=1);

use App\Service\Swoole\EventHandler\RequestEventHandler;
use App\Service\Swoole\EventHandler\StartEventHandler;
use App\Service\Swoole\EventHandler\WorkerStartEventHandler;
use App\Service\Swoole\EventHandler\TaskEventHandler;
use App\Service\Swoole\EventHandler\TaskFinishEventHandler;
use App\Service\Swoole\EventHandler\ShutdownEventHandler;

return [
    'host' => $_ENV['SWOOLE_HOST'] ?? '0.0.0.0',
    'port' => (int)($_ENV['SWOOLE_PORT'] ?? 9501),

    'settings' => [
        'worker_num' => (int)($_ENV['SWOOLE_WORKERS'] ?? 4),
        'task_worker_num' => (int)($_ENV['SWOOLE_TASK_WORKERS'] ?? 2),
        'max_coroutine' => (int)($_ENV['SWOOLE_MAX_COROUTINE'] ?? 10000),
        'enable_coroutine' => true,
        'task_enable_coroutine' => true,
        'hook_flags' => SWOOLE_HOOK_ALL,
        'log_level' => SWOOLE_LOG_WARNING,
        'log_file' => '/var/www/app/runtime/logs/swoole.log',
        'pid_file' => '/var/www/app/runtime/tmp/server.pid',
        'open_tcp_nodelay' => true,
        'max_request' => 100000,
        'reload_async' => true,
        'tcp_fastopen' => true,
    ],

    'events' => [
        'start' => StartEventHandler::class,
        'workerStart' => WorkerStartEventHandler::class,
        'request' => RequestEventHandler::class,
        'task' => TaskEventHandler::class,
        'finish' => TaskFinishEventHandler::class,
        'shutdown' => ShutdownEventHandler::class,
    ],
];
