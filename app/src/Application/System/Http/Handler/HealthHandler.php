<?php

declare(strict_types=1);

namespace App\Application\System\Http\Handler;

use App\Application\System\HealthCheck\CacheHealthCheck;
use App\Application\System\HealthCheck\DatabaseHealthCheck;
use App\Application\System\HealthCheck\ExchangeRateHealthCheck;
use App\Application\System\HealthCheck\TelegramHealthCheck;
use App\Application\System\Service\HealthService;
use App\Service\Attribute\Route;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;

#[Route('/health')]
class HealthHandler
{
    public function __construct(
        private HealthService $healthService,
        private DatabaseHealthCheck $databaseCheck,
        private CacheHealthCheck $cacheCheck,
        private TelegramHealthCheck $telegramCheck,
        private ExchangeRateHealthCheck $exchangeRateCheck
    ) {
        $this->healthService->registerCheck($this->databaseCheck);
        $this->healthService->registerCheck($this->cacheCheck);
        $this->healthService->registerCheck($this->telegramCheck);
        $this->healthService->registerCheck($this->exchangeRateCheck);
    }

    public function handle(Request $request, Response $response): void
    {
        $checks = $this->healthService->runAll();
        $allHealthy = $this->healthService->isAllHealthy($checks);

        $response->json([
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => date('c'),
            'checks' => $checks,
        ], $allHealthy ? 200 : 503);
    }
}
