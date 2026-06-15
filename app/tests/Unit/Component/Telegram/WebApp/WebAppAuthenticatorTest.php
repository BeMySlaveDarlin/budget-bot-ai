<?php

declare(strict_types=1);

namespace Tests\Unit\Component\Telegram\WebApp;

use App\Component\Telegram\Repository\UserRepository;
use App\Component\Telegram\WebApp\WebAppAuthenticator;
use App\Service\Http\Context\Request\Request;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

final class WebAppAuthenticatorTest extends TestCase
{
    private const string BOT_TOKEN = 'test-token-123';

    use MockeryPHPUnitIntegration;

    private MockInterface $userRepository;
    private WebAppAuthenticator $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userRepository = Mockery::mock(UserRepository::class);
        $this->userRepository->shouldNotReceive('findByTelegramId');
        $this->sut = new WebAppAuthenticator($this->userRepository);
    }

    private function requestWithInitData(string $initData): MockInterface
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getHeader')->with('x-telegram-init-data')->andReturn($initData);
        $request->shouldReceive('getQueryParam')->with('chat_id', '')->andReturn('');
        $request->shouldReceive('getQueryParam')->with('ts', '')->andReturn('');
        $request->shouldReceive('getQueryParam')->with('sig', '')->andReturn('');

        return $request;
    }

    private function requestWithSignature(mixed $chatId, mixed $ts, mixed $sig): MockInterface
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getHeader')->with('x-telegram-init-data')->andReturn(null);
        $request->shouldReceive('getQueryParam')->with('chat_id', '')->andReturn($chatId);
        $request->shouldReceive('getQueryParam')->with('ts', '')->andReturn($ts);
        $request->shouldReceive('getQueryParam')->with('sig', '')->andReturn($sig);

        return $request;
    }

    public function testValidateReturnsNullWhenBotTokenIsEmpty(): void
    {
        $request = Mockery::mock(Request::class);

        $this->assertNull($this->sut->validate($request, ''));
    }

    public function testValidateReturnsNullWhenInitDataHashIsArray(): void
    {
        $request = $this->requestWithInitData('hash[]=x');

        $this->assertNull($this->sut->validate($request, self::BOT_TOKEN));
    }

    public function testValidateReturnsNullWhenInitDataContainsArrayValue(): void
    {
        $request = $this->requestWithInitData('auth_date=123&hash=abc&user[]=y');

        $this->assertNull($this->sut->validate($request, self::BOT_TOKEN));
    }

    public function testValidateReturnsNullWhenInitDataContainsIntegerKey(): void
    {
        $request = $this->requestWithInitData('0=x&hash=abc');

        $this->assertNull($this->sut->validate($request, self::BOT_TOKEN));
    }

    public function testValidateReturnsNullWhenSigQueryParamIsArray(): void
    {
        $request = $this->requestWithSignature('42', '123', ['x']);

        $this->assertNull($this->sut->validate($request, self::BOT_TOKEN));
    }

    public function testValidateReturnsNullWhenChatIdQueryParamIsArray(): void
    {
        $request = $this->requestWithSignature(['1'], '123', 'abc');

        $this->assertNull($this->sut->validate($request, self::BOT_TOKEN));
    }

    public function testValidateReturnsUserForValidUrlSignature(): void
    {
        $chatId = '777';
        $ts = (string) time();
        $sig = hash_hmac('sha256', "{$chatId}:{$ts}", self::BOT_TOKEN);

        $request = $this->requestWithSignature($chatId, $ts, $sig);

        $this->assertSame(
            ['id' => 0, 'chat_id' => 777, 'auth_type' => 'url_signature'],
            $this->sut->validate($request, self::BOT_TOKEN)
        );
    }

    public function testValidateReturnsNullForExpiredUrlSignature(): void
    {
        $chatId = '777';
        $ts = (string) (time() - 100000);
        $sig = hash_hmac('sha256', "{$chatId}:{$ts}", self::BOT_TOKEN);

        $request = $this->requestWithSignature($chatId, $ts, $sig);

        $this->assertNull($this->sut->validate($request, self::BOT_TOKEN));
    }
}
