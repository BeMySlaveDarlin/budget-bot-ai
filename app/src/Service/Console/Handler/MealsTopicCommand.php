<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Application\Meals\Repository\BotConfigRepository;
use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;

#[Command(name: 'meals:topic', description: 'Show / set / clear the topic meals bot is bound to')]
final class MealsTopicCommand implements CommandInterface
{
    private const string BOT_CODE = 'meals';

    public function __construct(
        private BotConfigRepository $botConfig
    ) {
    }

    public function execute(array $args = []): int
    {
        $action = $args[0] ?? 'show';

        return match ($action) {
            'show' => $this->show(),
            'set' => $this->set($args[1] ?? null),
            'clear' => $this->clear(),
            default => $this->usage(),
        };
    }

    private function show(): int
    {
        $topicId = $this->botConfig->getBoundTopicId(self::BOT_CODE);

        echo $topicId !== null
            ? "meals bound to topic_id={$topicId}\n"
            : "meals not bound to any topic (reacts in all topics)\n";

        return 0;
    }

    private function set(?string $raw): int
    {
        if ($raw === null || !ctype_digit($raw)) {
            echo "Usage: meals:topic set <topic_id>\n";

            return 1;
        }

        $this->botConfig->setBoundTopicId(self::BOT_CODE, (int) $raw);
        echo "meals bound to topic_id={$raw}\n";

        return 0;
    }

    private function clear(): int
    {
        $this->botConfig->setBoundTopicId(self::BOT_CODE, null);
        echo "meals topic binding cleared (reacts in all topics)\n";

        return 0;
    }

    private function usage(): int
    {
        echo "Usage: meals:topic [show|set <topic_id>|clear]\n";

        return 1;
    }

    public function getName(): string
    {
        return 'meals:topic';
    }

    public function getDescription(): string
    {
        return 'Show / set / clear the topic meals bot is bound to';
    }
}
