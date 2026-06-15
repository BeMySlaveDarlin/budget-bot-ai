<?php

declare(strict_types=1);

namespace Tests\Unit\Component\Telegram;

use App\Component\Telegram\WebhookTokenValidator;
use App\Service\Config\Config;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class WebhookTokenValidatorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function validator(string $expectedToken): WebhookTokenValidator
    {
        $config = Mockery::mock(Config::class);
        $config->shouldReceive('get')
            ->with('telegram.meals_bot_token', '')
            ->andReturn($expectedToken);

        return new WebhookTokenValidator($config);
    }

    public function testValidateReturnsTrueOnMatchingToken(): void
    {
        $validator = $this->validator('secret-token');

        $this->assertTrue($validator->validate('telegram.meals_bot_token', 'secret-token'));
    }

    public function testValidateReturnsFalseOnWrongToken(): void
    {
        $validator = $this->validator('secret-token');

        $this->assertFalse($validator->validate('telegram.meals_bot_token', 'forged'));
    }

    public function testValidateReturnsFalseWhenExpectedTokenIsEmpty(): void
    {
        $validator = $this->validator('');

        $this->assertFalse($validator->validate('telegram.meals_bot_token', ''));
    }

    public function testValidateReturnsFalseWhenActualTokenIsEmpty(): void
    {
        $validator = $this->validator('secret-token');

        $this->assertFalse($validator->validate('telegram.meals_bot_token', ''));
    }
}
