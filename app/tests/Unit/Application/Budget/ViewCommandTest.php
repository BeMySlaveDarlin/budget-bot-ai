<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Budget;

use App\Application\Budget\Command\ViewCommand;
use App\Application\Budget\DTO\CommandContext;
use App\Component\Telegram\Repository\ChatRepository;
use App\Service\Config\Config;
use App\Service\Task\TaskManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class ViewCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const string BOT_TOKEN = '999999:fresh-budget-token';

    private function command(): ViewCommand
    {
        $config = Mockery::mock(Config::class);
        $config->shouldReceive('get')->with('app.url', '')->andReturn('https://app.test');
        $config->shouldReceive('get')->with('telegram.budget_bot_token', '')->andReturn(self::BOT_TOKEN);

        return new ViewCommand(
            $config,
            Mockery::mock(TaskManager::class),
            Mockery::mock(ChatRepository::class)
        );
    }

    private function context(string $chatType): CommandContext
    {
        return new CommandContext(
            chat: ['id' => 123, 'type' => $chatType, 'settings' => []],
            user: ['id' => 1],
            command: 'view',
            args: '',
            telegramChatId: -99990030,
            messageId: 1
        );
    }

    public function testGetKeyboardSignsUrlWithBudgetBotTokenForGroupChat(): void
    {
        $keyboard = $this->command()->getKeyboard($this->context('group'));

        $this->assertNotNull($keyboard);
        $button = $keyboard['inline_keyboard'][0][0];
        $this->assertArrayHasKey('url', $button);

        parse_str((string) parse_url($button['url'], PHP_URL_QUERY), $query);
        $this->assertSame('123', $query['chat_id']);
        $this->assertArrayHasKey('ts', $query);
        $this->assertArrayHasKey('sig', $query);

        $expectedSig = hash_hmac('sha256', "123:{$query['ts']}", self::BOT_TOKEN);
        $this->assertSame($expectedSig, $query['sig']);
    }

    public function testGetKeyboardUsesWebAppWithoutSignatureForPrivateChat(): void
    {
        $keyboard = $this->command()->getKeyboard($this->context('private'));

        $this->assertNotNull($keyboard);
        $button = $keyboard['inline_keyboard'][0][0];
        $this->assertArrayHasKey('web_app', $button);
        $this->assertStringNotContainsString('sig=', $button['web_app']['url']);
    }
}
