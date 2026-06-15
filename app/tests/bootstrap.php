<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

DG\BypassFinals::enable();

$envFile = dirname(__DIR__, 2) . '/.env';
if (is_file($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname($envFile));
    $dotenv->safeLoad();
}
