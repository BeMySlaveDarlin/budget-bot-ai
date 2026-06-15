<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Component\Telegram\Repository\BotConfigRepository;
use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;

#[Command(name: 'bot:topic', description: 'Show / set / clear the topic a bot is bound to')]
final class BotTopicCommand implements CommandInterface
{
    private const array BOT_CODES = ['budget', 'meals'];

    public function __construct(
        private BotConfigRepository $botConfig
    ) {
    }

    public function execute(array $args = []): int
    {
        $botCode = $args[0] ?? null;
        if ($botCode === null || !in_array($botCode, self::BOT_CODES, true)) {
            echo "Usage: bot:topic <budget|meals> [show|set <topic_id>|clear]\n";

            return 1;
        }

        $action = $args[1] ?? 'show';

        return match ($action) {
            'show' => $this->show($botCode),
            'set' => $this->set($botCode, $args[2] ?? null),
            'clear' => $this->clear($botCode),
            default => $this->usage(),
        };
    }

    private function show(string $botCode): int
    {
        $topicId = $this->botConfig->getBoundTopicId($botCode);

        echo $topicId !== null
            ? "{$botCode} bound to topic_id={$topicId}\n"
            : "{$botCode} not bound to any topic (reacts in all topics)\n";

        return 0;
    }

    private function set(string $botCode, ?string $raw): int
    {
        if ($raw === null || !ctype_digit($raw)) {
            echo "Usage: bot:topic {$botCode} set <topic_id>\n";

            return 1;
        }

        $this->botConfig->setBoundTopicId($botCode, (int) $raw);
        echo "{$botCode} bound to topic_id={$raw}\n";

        return 0;
    }

    private function clear(string $botCode): int
    {
        $this->botConfig->setBoundTopicId($botCode, null);
        echo "{$botCode} topic binding cleared (reacts in all topics)\n";

        return 0;
    }

    private function usage(): int
    {
        echo "Usage: bot:topic <budget|meals> [show|set <topic_id>|clear]\n";

        return 1;
    }

    public function getName(): string
    {
        return 'bot:topic';
    }

    public function getDescription(): string
    {
        return 'Show / set / clear the topic a bot is bound to';
    }
}
