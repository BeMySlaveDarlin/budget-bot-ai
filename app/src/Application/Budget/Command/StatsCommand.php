<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Budget\Command\Attribute\BotCommand;
use App\Application\Budget\Command\Contract\BotCommandInterface;
use App\Application\Budget\DTO\CommandContext;
use App\Application\Budget\Service\StatsService;
use App\Component\LLM\Exception\TokenLimitExceededException;
use App\Service\Console\Repository\CommandLogRepository;
use DI\Attribute\Injectable;

#[Injectable]
#[BotCommand(command: 'stats', description: 'Статистика за N месяцев', showPending: true)]
class StatsCommand implements BotCommandInterface
{
    public function __construct(
        private StatsService $statsService,
        private CommandLogRepository $commandLogRepo
    ) {
    }

    public function execute(CommandContext $ctx): ?string
    {
        [$months, $verbose] = $this->parseArgs($ctx->args);
        $currency = $ctx->getCurrency();

        try {
            $result = $this->statsService->getStats($ctx->getChatId(), $months, $currency, $verbose);
        } catch (TokenLimitExceededException $e) {
            return "⚠️ Дневной лимит токенов исчерпан ({$e->used}/{$e->limit}). Попробуй завтра.";
        }

        if ($result['tokens'] > 0) {
            $this->logCommand($ctx, $result['text'], $result['tokens']);
        }

        return $result['text'];
    }

    public function getKeyboard(CommandContext $ctx): ?array
    {
        return null;
    }

    private function parseArgs(string $args): array
    {
        $months = 1;
        $verbose = false;

        if (preg_match('/\b([1-9]|1[0-2])\b/', $args, $matches)) {
            $months = (int) $matches[1];
        }

        if (preg_match('/\bv\b/i', $args)) {
            $verbose = true;
        }

        return [$months, $verbose];
    }

    private function logCommand(CommandContext $ctx, string $response, int $tokens): void
    {
        $inputTokens = (int) ($tokens * 0.7);
        $outputTokens = $tokens - $inputTokens;
        $this->commandLogRepo->create(
            $ctx->getChatId(),
            $ctx->getUserId(),
            'stats',
            $ctx->args,
            $response,
            $inputTokens,
            $outputTokens
        );
    }
}
