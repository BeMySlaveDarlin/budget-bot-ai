<?php

declare(strict_types=1);

namespace Tests\Unit\Component\Telegram\WebApp;

use App\Component\Telegram\Repository\ChatUserRepository;
use App\Component\Telegram\WebApp\WebAppAccessResolver;
use App\Component\Telegram\WebApp\WebAppAuthenticator;
use App\Service\Http\Context\Request\Request;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

final class WebAppAccessResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const TOKEN = 'bot-token';

    private MockInterface $authenticator;
    private MockInterface $members;
    private MockInterface $request;
    private WebAppAccessResolver $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticator = Mockery::mock(WebAppAuthenticator::class);
        $this->members = Mockery::mock(ChatUserRepository::class);
        $this->request = Mockery::mock(Request::class);

        $this->sut = new WebAppAccessResolver($this->authenticator, $this->members);
    }

    public function testResolveDeniesWithUnauthorizedWhenAuthNull(): void
    {
        $this->authenticator->shouldReceive('validate')
            ->once()
            ->with($this->request, self::TOKEN)
            ->andReturnNull();

        $this->members->shouldNotReceive('findByChatAndUser');

        $access = $this->sut->resolve($this->request, self::TOKEN, 999);

        $this->assertFalse($access->granted);
        $this->assertSame(401, $access->denyStatus);
        $this->assertSame('Unauthorized', $access->denyError);
        $this->assertNull($access->user);
    }

    public function testResolveUsesSignedChatIdAndIgnoresRequestedChatId(): void
    {
        $user = ['id' => 0, 'chat_id' => 555, 'auth_type' => 'url_signature'];
        $this->authenticator->shouldReceive('validate')
            ->once()
            ->with($this->request, self::TOKEN)
            ->andReturn($user);

        $this->members->shouldNotReceive('findByChatAndUser');

        $access = $this->sut->resolve($this->request, self::TOKEN, 999);

        $this->assertTrue($access->granted);
        $this->assertSame(555, $access->chatId);
        $this->assertSame($user, $access->user);
    }

    public function testResolveGrantsInitDataWhenUserIsMember(): void
    {
        $user = ['id' => 42, 'first_name' => 'A'];
        $this->authenticator->shouldReceive('validate')
            ->once()
            ->with($this->request, self::TOKEN)
            ->andReturn($user);

        $this->members->shouldReceive('findByChatAndUser')
            ->once()
            ->with(7, 42)
            ->andReturn(['chat_id' => 7, 'user_id' => 42]);

        $access = $this->sut->resolve($this->request, self::TOKEN, 7);

        $this->assertTrue($access->granted);
        $this->assertSame(7, $access->chatId);
        $this->assertSame($user, $access->user);
    }

    public function testResolveDeniesInitDataWhenUserNotMember(): void
    {
        $user = ['id' => 42, 'first_name' => 'A'];
        $this->authenticator->shouldReceive('validate')
            ->once()
            ->with($this->request, self::TOKEN)
            ->andReturn($user);

        $this->members->shouldReceive('findByChatAndUser')
            ->once()
            ->with(7, 42)
            ->andReturnNull();

        $access = $this->sut->resolve($this->request, self::TOKEN, 7);

        $this->assertFalse($access->granted);
        $this->assertSame(403, $access->denyStatus);
        $this->assertSame('Forbidden', $access->denyError);
    }

    public function testResolveDeniesInitDataWhenChatIdMissing(): void
    {
        $user = ['id' => 42, 'first_name' => 'A'];
        $this->authenticator->shouldReceive('validate')
            ->once()
            ->with($this->request, self::TOKEN)
            ->andReturn($user);

        $this->members->shouldNotReceive('findByChatAndUser');

        $access = $this->sut->resolve($this->request, self::TOKEN, 0);

        $this->assertFalse($access->granted);
        $this->assertSame(400, $access->denyStatus);
        $this->assertSame('chat_id required', $access->denyError);
    }

    public function testValidateProxiesToAuthenticator(): void
    {
        $expected = ['id' => 42];
        $this->authenticator->shouldReceive('validate')
            ->once()
            ->with($this->request, self::TOKEN)
            ->andReturn($expected);

        $this->assertSame($expected, $this->sut->validate($this->request, self::TOKEN));
    }
}
