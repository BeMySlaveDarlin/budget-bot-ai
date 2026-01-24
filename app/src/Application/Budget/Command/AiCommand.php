<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Budget\Command\Attribute\BotCommand;
use App\Application\Budget\Command\Contract\BotCommandInterface;
use App\Application\Budget\DTO\CommandContext;
use App\Application\Budget\Service\AiService;
use App\Component\LLM\Exception\TokenLimitExceededException;
use App\Service\Console\Repository\CommandLogRepository;
use DI\Attribute\Injectable;

#[Injectable]
#[BotCommand(command: 'ai', description: 'AI анализ расходов', showPending: true)]
class AiCommand implements BotCommandInterface
{
    public function __construct(
        private AiService $aiService,
        private CommandLogRepository $commandLogRepo
    ) {
    }

    public function execute(CommandContext $ctx): ?string
    {
        $question = $this->removeMonthsFromArgs($ctx->args);

        if (empty(trim($question))) {
            return 'Укажи вопрос: /ai сколько потратил на еду?';
        }

        $months = $this->parseMonths($ctx->args);
        $currency = $ctx->getCurrency();

        try {
            $result = $this->aiService->ask($ctx->getChatId(), $question, $months, $currency);
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

    private function parseMonths(string $args): int
    {
        if (preg_match('/\b([1-9]|1[0-2])\b/', $args, $matches)) {
            return (int) $matches[1];
        }

        return 1;
    }

    private function removeMonthsFromArgs(string $args): string
    {
        return trim(preg_replace('/\b([1-9]|1[0-2])\b/', '', $args));
    }

    private function logCommand(CommandContext $ctx, string $response, int $tokens): void
    {
        $inputTokens = (int) ($tokens * 0.7);
        $outputTokens = $tokens - $inputTokens;
        $this->commandLogRepo->create(
            $ctx->getChatId(),
            $ctx->getUserId(),
            'ai',
            $ctx->args,
            $response,
            $inputTokens,
            $outputTokens
        );
    }
}
