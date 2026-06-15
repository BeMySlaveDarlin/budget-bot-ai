<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Meals\Http\Middleware;

use App\Application\Meals\Http\Middleware\MealsWebAppAuth;
use App\Component\Telegram\WebApp\WebAppAccess;
use App\Component\Telegram\WebApp\WebAppAccessResolver;
use App\Service\Config\Config;
use App\Service\Http\Context\Request\Request;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

final class MealsWebAppAuthTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function auth(string $botToken, MockInterface $resolver): MealsWebAppAuth
    {
        $config = Mockery::mock(Config::class);
        $config->shouldReceive('get')
            ->with('telegram.meals_bot_token', '')
            ->andReturn($botToken);

        return new MealsWebAppAuth($resolver, $config);
    }

    public function testValidateDelegatesWithMealsToken(): void
    {
        $request = Mockery::mock(Request::class);

        $resolver = Mockery::mock(WebAppAccessResolver::class);
        $resolver->shouldReceive('validate')
            ->once()
            ->with($request, 'meals-tok')
            ->andReturnNull();

        $this->assertNull($this->auth('meals-tok', $resolver)->validate($request));
    }

    public function testValidatePassesThroughResolverArrayResult(): void
    {
        $request = Mockery::mock(Request::class);
        $expected = ['id' => 0, 'chat_id' => 99, 'auth_type' => 'url_signature'];

        $resolver = Mockery::mock(WebAppAccessResolver::class);
        $resolver->shouldReceive('validate')
            ->once()
            ->with($request, 'meals-tok')
            ->andReturn($expected);

        $this->assertSame($expected, $this->auth('meals-tok', $resolver)->validate($request));
    }

    public function testResolveAccessDelegatesToResolverWithChatId(): void
    {
        $request = Mockery::mock(Request::class);
        $access = WebAppAccess::grant(7, ['id' => 42]);

        $resolver = Mockery::mock(WebAppAccessResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with($request, 'meals-tok', 7)
            ->andReturn($access);

        $this->assertSame($access, $this->auth('meals-tok', $resolver)->resolveAccess($request, 7));
    }

    public function testResolveAccessPassesThroughDenyAccess(): void
    {
        $request = Mockery::mock(Request::class);
        $access = WebAppAccess::deny(403, 'Forbidden');

        $resolver = Mockery::mock(WebAppAccessResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with($request, 'meals-tok', 5)
            ->andReturn($access);

        $result = $this->auth('meals-tok', $resolver)->resolveAccess($request, 5);

        $this->assertSame($access, $result);
        $this->assertFalse($result->granted);
        $this->assertSame(403, $result->denyStatus);
    }
}
