<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Budget\Command\Attribute\BotCommand;
use App\Application\Budget\Command\Contract\BotCommandInterface;
use App\Application\Budget\DTO\CommandContext;
use App\Application\System\HealthCheck\CacheHealthCheck;
use App\Application\System\HealthCheck\DatabaseHealthCheck;
use App\Application\System\HealthCheck\ExchangeRateHealthCheck;
use App\Application\System\HealthCheck\LlmHealthCheck;
use App\Component\ExchangeRate\Repository\ExchangeRateRepository;
use App\Component\Telegram\Repository\MessageRepository;
use DI\Attribute\Injectable;

#[Injectable]
#[BotCommand(command: 'status', description: 'Диагностика системы')]
class StatusCommand implements BotCommandInterface
{
    public function __construct(
        private DatabaseHealthCheck $dbCheck,
        private CacheHealthCheck $cacheCheck,
        private ExchangeRateHealthCheck $exchangeCheck,
        private LlmHealthCheck $llmCheck,
        private MessageRepository $messageRepo,
        private ExchangeRateRepository $exchangeRepo
    ) {
    }

    public function execute(CommandContext $ctx): ?string
    {
        $checks = [];
        $allOk = true;

        $dbResult = $this->dbCheck->check();
        if ($dbResult['healthy']) {
            $checks[] = "✅ Database: OK ({$dbResult['latency_ms']}ms)";
        } else {
            $checks[] = "❌ Database: " . ($dbResult['error'] ?? 'Failed');
            $allOk = false;
        }

        $cacheResult = $this->cacheCheck->check();
        if ($cacheResult['healthy']) {
            $l1 = $cacheResult['l1_enabled'] ? 'on' : 'off';
            $l2 = $cacheResult['l2_enabled'] ? 'on' : 'off';
            $checks[] = "✅ Cache: OK (L1: {$l1}, L2: {$l2})";
        } else {
            $checks[] = "❌ Cache: " . ($cacheResult['error'] ?? 'Failed');
            $allOk = false;
        }

        try {
            $rates = $this->exchangeRepo->getAllRates('THB');
            if (!empty($rates)) {
                $currencies = array_column($rates, 'currency_from');
                $checks[] = "✅ Exchange: " . count($rates) . " rates (" . implode(', ', array_slice($currencies, 0, 3)) . "...)";
            } else {
                $checks[] = "⚠️ Exchange: No rates (run exchange:update)";
            }
        } catch (\Throwable $e) {
            $checks[] = "❌ Exchange: " . $e->getMessage();
            $allOk = false;
        }

        $llmResult = $this->llmCheck->check();
        if ($llmResult['healthy']) {
            $checks[] = "✅ LLM: Claude ({$llmResult['latency_ms']}ms)";
        } else {
            $checks[] = "❌ LLM: " . ($llmResult['error'] ?? 'Connection failed');
            $allOk = false;
        }

        try {
            $msgCount = $this->messageRepo->countForChat($ctx->getChatId());
            $checks[] = "📊 Messages in chat: {$msgCount}";
        } catch (\Throwable $e) {
            $checks[] = "❌ Messages: " . $e->getMessage();
        }

        $checks[] = "👤 User ID: {$ctx->getUserId()}, Enabled: Yes";
        $checks[] = "💬 Chat ID: {$ctx->getChatId()}";

        $statusIcon = $allOk ? '✅' : '⚠️';
        $text = "{$statusIcon} <b>System Status</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= implode("\n", $checks);
        $text .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "🕐 " . date('Y-m-d H:i:s T');

        return $text;
    }

    public function getKeyboard(CommandContext $ctx): ?array
    {
        return null;
    }
}
