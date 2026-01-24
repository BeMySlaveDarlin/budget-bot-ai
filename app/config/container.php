<?php

use App\Service\Cache\CacheInterface;
use App\Service\Cache\TieredCacheService;
use App\Service\Logging\LoggerFactory;
use App\Service\RateLimit\Contract\RateLimiterInterface;
use App\Service\RateLimit\TokenBucketRateLimiter;
use App\Service\Swoole\SwooleServerFactory;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Swoole\Http\Server;

use function DI\autowire;
use function DI\factory;

return [
    Server::class => factory([SwooleServerFactory::class, 'create']),

    LoggerInterface::class => factory(fn(LoggerFactory $factory) => $factory->create()),
    'logger.app' => factory(fn(LoggerFactory $factory) => $factory->create('app')),
    'logger.http' => factory(fn(LoggerFactory $factory) => $factory->create('http')),
    'logger.error' => factory(fn(LoggerFactory $factory) => $factory->create('error')),

    CacheInterface::class => autowire(TieredCacheService::class),

    RateLimiterInterface::class => autowire(TokenBucketRateLimiter::class),

    Client::class => factory(fn() => new Client([
        'timeout' => 90,
        'connect_timeout' => 30,
        'decode_content' => true,
        'headers' => [
            'Accept-Encoding' => 'identity',
        ],
    ])),
];
