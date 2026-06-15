<?php

declare(strict_types=1);

namespace App\Application\Meals\Service;

use App\Application\Meals\Repository\MealFactConflictRepository;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;

#[Injectable]
final class MealAppService
{
    private const array ALLOWED_ACTIONS = ['accept_new', 'reject', 'keep_both'];

    public function __construct(
        private MealFactConflictRepository $conflictRepository,
        private LoggerInterface $logger
    ) {
    }

    public function resolveConflict(int $chatId, int $conflictId, string $action): bool
    {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            $this->logger->warning('[Meals] invalid conflict action', [
                'chat_id' => $chatId,
                'conflict_id' => $conflictId,
                'action' => $action,
            ]);

            return false;
        }

        $resolved = $this->conflictRepository->resolve($conflictId, $chatId, $action);

        $this->logger->info('[Meals] conflict resolved', [
            'chat_id' => $chatId,
            'conflict_id' => $conflictId,
            'action' => $action,
            'resolved' => $resolved,
        ]);

        return $resolved;
    }
}
