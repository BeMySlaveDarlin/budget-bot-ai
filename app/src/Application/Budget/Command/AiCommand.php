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

        return $this->getCurrentQuarterMonthsBack();
    }

    private function removeMonthsFromArgs(string $args): string
    {
        return trim(preg_replace('/\b([1-9]|1[0-2])\b/', '', $args));
    }

    private function getCurrentQuarterMonthsBack(): int
    {
        $currentMonth = (int) date('n');

        return match ($currentMonth) {
            3 => 1,     // март, начало Q1
            4 => 2,     // апрель, 2 месяца с начала Q1
            5 => 3,     // май, 3 месяца с начала Q1
            6 => 1,     // июнь, начало Q2
            7 => 2,     // июль, 2 месяца с начала Q2
            8 => 3,     // август, 3 месяца с начала Q2
            9 => 1,     // сентябрь, начало Q3
            10 => 2,    // октябрь, 2 месяца с начала Q3
            11 => 3,    // ноябрь, 3 месяца с начала Q3
            12 => 1,    // декабрь, начало Q4
            1 => 2,     // январь, 2 месяца с начала Q4
            2 => 3,     // февраль, 3 месяца с начала Q4
            default => 1
        };
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
