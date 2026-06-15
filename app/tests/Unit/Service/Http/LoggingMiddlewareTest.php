<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Http;

use App\Service\Http\Context\HttpContext;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;
use App\Service\Http\Contract\ContextHandlerInterface;
use App\Service\Http\Middleware\Handler\LoggingMiddleware;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

final class LoggingMiddlewareTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function maskPath(string $path): string
    {
        $middleware = new LoggingMiddleware(new NullLogger());

        return new ReflectionClass($middleware)->getMethod('maskPath')->invoke($middleware, $path);
    }

    private function captureStartLogContext(array $queryParams, string $path = '/report'): array
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getPath')->andReturn($path);
        $request->shouldReceive('getMethod')->andReturn('GET');
        $request->shouldReceive('getQueryParams')->andReturn($queryParams);
        $request->shouldReceive('getHeader')->with('User-Agent')->andReturn('phpunit');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('getStatusCode')->andReturn(200);

        $context = Mockery::mock(HttpContext::class);
        $context->shouldReceive('getRequest')->andReturn($request);
        $context->shouldReceive('getResponse')->andReturn($response);

        $handler = Mockery::mock(ContextHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->with($context);

        $startContext = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with('HTTP request started', Mockery::capture($startContext));
        $logger->shouldReceive('info')
            ->once()
            ->with('HTTP request completed', Mockery::type('array'));
        $logger->shouldNotReceive('warning');
        $logger->shouldNotReceive('error');

        new LoggingMiddleware($logger)->process($context, $handler);

        return $startContext;
    }

    public static function maskedPathsProvider(): array
    {
        return [
            'legacy telegram route' => ['/telegram/123456:ABC-secret', '/telegram/***'],
            'budget route' => ['/telegram/budget/123456:ABC-secret', '/telegram/budget/***'],
            'meal route' => ['/telegram/meal/local-meals-test-token', '/telegram/meal/***'],
        ];
    }

    public static function untouchedPathsProvider(): array
    {
        return [
            'health' => ['/health'],
            'metrics' => ['/metrics'],
            'report' => ['/report'],
            'root' => ['/'],
        ];
    }

    #[DataProvider('maskedPathsProvider')]
    public function testMaskPathMasksWebhookTokens(string $path, string $expected): void
    {
        $this->assertSame($expected, $this->maskPath($path));
    }

    #[DataProvider('untouchedPathsProvider')]
    public function testMaskPathLeavesRegularPathsUntouched(string $path): void
    {
        $this->assertSame($path, $this->maskPath($path));
    }

    public function testProcessMasksSigInStartLogAndKeepsOtherParams(): void
    {
        $logContext = $this->captureStartLogContext(['sig' => 'abc', 'ts' => '123', 'chat_id' => '42']);

        $this->assertSame('***', $logContext['query']['sig']);
        $this->assertSame('123', $logContext['query']['ts']);
        $this->assertSame('42', $logContext['query']['chat_id']);
    }

    public function testProcessMasksSensitiveQueryKeysCaseInsensitively(): void
    {
        $logContext = $this->captureStartLogContext(['SIG' => 'abc', 'Token' => 'xyz', 'plain' => 'ok']);

        $this->assertSame('***', $logContext['query']['SIG']);
        $this->assertSame('***', $logContext['query']['Token']);
        $this->assertSame('ok', $logContext['query']['plain']);
    }

    public function testProcessMasksSensitiveKeysInNestedQueryArrays(): void
    {
        $logContext = $this->captureStartLogContext(['filter' => ['hash' => 'deadbeef', 'name' => 'food']]);

        $this->assertSame('***', $logContext['query']['filter']['hash']);
        $this->assertSame('food', $logContext['query']['filter']['name']);
    }

    public function testProcessMasksWebhookTokenInLoggedPath(): void
    {
        $logContext = $this->captureStartLogContext([], '/telegram/meal/secret-token');

        $this->assertSame('/telegram/meal/***', $logContext['path']);
    }
}
