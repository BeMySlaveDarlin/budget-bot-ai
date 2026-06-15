<?php

declare(strict_types=1);

namespace Tests\Unit\Component\Telegram;

use App\Component\Telegram\TelegramClient;
use App\Service\Config\Config;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class TelegramClientDryRunTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function client(bool $dryRun, LoggerInterface $logger): TelegramClient
    {
        $config = Mockery::mock(Config::class);
        $config->shouldReceive('get')->with('telegram.budget_bot_token', '')->andReturn('token');
        $config->shouldReceive('get')->with('telegram.dry_run', false)->andReturn($dryRun);

        return new TelegramClient($config, $logger);
    }

    public function testDryRunSkipsHttpAndReturnsOk(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with('[dry-run] telegram sendMessage', Mockery::on(
                static fn (array $ctx): bool => ($ctx['params']['chat_id'] ?? null) === 42
            ));
        $logger->shouldReceive('error')->never();

        $result = $this->client(true, $logger)->sendMessage(42, 'hello');

        $this->assertSame(['ok' => true, 'result' => []], $result);
    }

    public function testDryRunCoversAllOutgoingMethods(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->times(3);
        $logger->shouldReceive('error')->never();

        $client = $this->client(true, $logger);

        $this->assertSame(['ok' => true, 'result' => []], $client->answerCallbackQuery('cb-1'));
        $this->assertSame(['ok' => true, 'result' => []], $client->editMessageText(1, 2, 'x'));
        $this->assertSame(['ok' => true, 'result' => []], $client->deleteMessage(1, 2));
    }
}
