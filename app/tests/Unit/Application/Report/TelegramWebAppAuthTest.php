<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Report;

use App\Application\Report\Http\Middleware\TelegramWebAppAuth;
use App\Component\Telegram\WebApp\WebAppAccess;
use App\Component\Telegram\WebApp\WebAppAccessResolver;
use App\Service\Config\Config;
use App\Service\Http\Context\Request\Request;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

final class TelegramWebAppAuthTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function auth(string $botToken, MockInterface $resolver): TelegramWebAppAuth
    {
        $config = Mockery::mock(Config::class);
        $config->shouldReceive('get')
            ->with('telegram.budget_bot_token', '')
            ->andReturn($botToken);

        return new TelegramWebAppAuth($resolver, $config);
    }

    public function testValidateDelegatesNullToResolver(): void
    {
        $request = Mockery::mock(Request::class);

        $resolver = Mockery::mock(WebAppAccessResolver::class);
        $resolver->shouldReceive('validate')
            ->once()
            ->with($request, '123456:budget-token')
            ->andReturnNull();

        $this->assertNull($this->auth('123456:budget-token', $resolver)->validate($request));
    }

    public function testValidatePassesThroughResolverArrayResult(): void
    {
        $request = Mockery::mock(Request::class);
        $expected = ['id' => 7, 'chat_id' => 42, 'auth_type' => 'url_signature'];

        $resolver = Mockery::mock(WebAppAccessResolver::class);
        $resolver->shouldReceive('validate')
            ->once()
            ->with($request, '123456:budget-token')
            ->andReturn($expected);

        $this->assertSame($expected, $this->auth('123456:budget-token', $resolver)->validate($request));
    }

    public function testResolveAccessDelegatesToResolverWithChatId(): void
    {
        $request = Mockery::mock(Request::class);
        $access = WebAppAccess::grant(42, ['id' => 7]);

        $resolver = Mockery::mock(WebAppAccessResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with($request, '123456:budget-token', 42)
            ->andReturn($access);

        $this->assertSame($access, $this->auth('123456:budget-token', $resolver)->resolveAccess($request, 42));
    }

    public function testResolveAccessPassesThroughDenyAccess(): void
    {
        $request = Mockery::mock(Request::class);
        $access = WebAppAccess::deny(403, 'Forbidden');

        $resolver = Mockery::mock(WebAppAccessResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with($request, '123456:budget-token', 99)
            ->andReturn($access);

        $result = $this->auth('123456:budget-token', $resolver)->resolveAccess($request, 99);

        $this->assertSame($access, $result);
        $this->assertFalse($result->granted);
        $this->assertSame(403, $result->denyStatus);
    }
}
