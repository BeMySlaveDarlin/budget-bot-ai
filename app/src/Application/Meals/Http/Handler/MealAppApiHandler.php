<?php

declare(strict_types=1);

namespace App\Application\Meals\Http\Handler;

use App\Application\Meals\Http\Middleware\MealsWebAppAuth;
use App\Application\Meals\Repository\MealCookHistoryRepository;
use App\Application\Meals\Repository\MealFactConflictRepository;
use App\Application\Meals\Repository\MealFactRepository;
use App\Application\Meals\Repository\MealInventoryRepository;
use App\Application\Meals\Service\MealAppService;
use App\Service\Attribute\Route;
use App\Service\Database\DatabaseException;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;

final class MealAppApiHandler
{
    private const RESOLVE_ACTIONS = ['accept_new', 'reject', 'keep_both'];

    public function __construct(
        private MealInventoryRepository $inventory,
        private MealFactRepository $facts,
        private MealCookHistoryRepository $history,
        private MealFactConflictRepository $conflicts,
        private MealAppService $appService,
        private MealsWebAppAuth $auth,
    ) {}

    #[Route('/api/meals/inventory', 'GET')]
    public function getInventory(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $items = array_map(
            fn(array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'available' => $this->toBool($row['available']),
                'quantity' => $this->toFloatOrNull($row['quantity']),
                'unit' => $row['unit'] !== null ? (string) $row['unit'] : null,
                'updated_at' => $row['updated_at'] ?? null,
            ],
            $this->inventory->getForChatFull($chatId)
        );

        $response->json(['items' => $items]);
    }

    #[Route('/api/meals/inventory', 'POST')]
    public function createInventory(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $body = $request->getBody();
        $name = $this->normalizeName($body['name'] ?? null);
        if ($name === null) {
            $response->json(['error' => 'name required'], 400);
            return;
        }

        $unit = $this->normalizeUnit($body['unit'] ?? null);
        if ($unit === false) {
            $response->json(['error' => 'unit too long'], 400);
            return;
        }

        $quantity = $this->normalizeQuantity($body['quantity'] ?? null);
        if ($quantity === false) {
            $response->json(['error' => 'quantity must be a non-negative number'], 400);
            return;
        }

        $available = $this->resolveAvailable($body['available'] ?? null, true);

        if ($this->inventory->existsByName($chatId, $name)) {
            $response->json(['error' => 'item already exists'], 409);
            return;
        }

        try {
            $id = $this->inventory->create($chatId, $name, $available, $quantity, $unit);
        } catch (DatabaseException $e) {
            if ($this->isUniqueViolation($e)) {
                $response->json(['error' => 'item already exists'], 409);
                return;
            }
            throw $e;
        }

        $response->json(['id' => $id, 'success' => true]);
    }

    #[Route('/api/meals/inventory', 'PUT')]
    public function updateInventory(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $body = $request->getBody();
        $id = $this->bodyId($body);
        if ($id === null) {
            $response->json(['error' => 'id required'], 400);
            return;
        }

        $name = $this->normalizeName($body['name'] ?? null);
        if ($name === null) {
            $response->json(['error' => 'name required'], 400);
            return;
        }

        $unit = $this->normalizeUnit($body['unit'] ?? null);
        if ($unit === false) {
            $response->json(['error' => 'unit too long'], 400);
            return;
        }

        $quantity = $this->normalizeQuantity($body['quantity'] ?? null);
        if ($quantity === false) {
            $response->json(['error' => 'quantity must be a non-negative number'], 400);
            return;
        }

        $available = $this->resolveAvailable($body['available'] ?? null, true);

        try {
            $success = $this->inventory->update($id, $chatId, $name, $available, $quantity, $unit);
        } catch (DatabaseException $e) {
            if ($this->isUniqueViolation($e)) {
                $response->json(['error' => 'item already exists'], 409);
                return;
            }
            throw $e;
        }

        $response->json(['success' => $success]);
    }

    #[Route('/api/meals/inventory', 'DELETE')]
    public function deleteInventory(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $id = (int) $request->getQueryParam('id', 0);
        if ($id === 0) {
            $response->json(['error' => 'id required'], 400);
            return;
        }

        $success = $this->inventory->delete($id, $chatId);

        $response->json(['success' => $success]);
    }

    #[Route('/api/meals/inventory/toggle', 'POST')]
    public function toggleInventory(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $body = $request->getBody();
        $id = $this->bodyId($body);
        if ($id === null) {
            $response->json(['error' => 'id required'], 400);
            return;
        }

        $available = $this->toBool($body['available'] ?? null);

        $success = $this->inventory->setAvailability($id, $chatId, $available);

        $response->json(['success' => $success]);
    }

    #[Route('/api/meals/facts', 'GET')]
    public function getFacts(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $includeInactive = $this->toBool($request->getQueryParam('include_inactive'));

        $items = array_map(
            fn(array $row): array => [
                'id' => (int) $row['id'],
                'fact' => (string) $row['fact'],
                'source' => (string) $row['source'],
                'is_active' => $this->toBool($row['is_active']),
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ],
            $this->facts->getAllForChat($chatId, $includeInactive)
        );

        $response->json(['items' => $items]);
    }

    #[Route('/api/meals/facts', 'POST')]
    public function createFact(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $fact = $this->normalizeFact($request->getBody()['fact'] ?? null);
        if ($fact === null) {
            $response->json(['error' => 'fact required'], 400);
            return;
        }

        $id = $this->facts->create($chatId, $fact, 'manual');

        $response->json(['id' => $id, 'success' => true]);
    }

    #[Route('/api/meals/facts', 'PUT')]
    public function updateFact(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $body = $request->getBody();
        $id = $this->bodyId($body);
        if ($id === null) {
            $response->json(['error' => 'id required'], 400);
            return;
        }

        $fact = $this->normalizeFact($body['fact'] ?? null);
        if ($fact === null) {
            $response->json(['error' => 'fact required'], 400);
            return;
        }

        $success = $this->facts->updateText($id, $chatId, $fact);

        $response->json(['success' => $success]);
    }

    #[Route('/api/meals/facts/deactivate', 'POST')]
    public function deactivateFact(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $id = $this->bodyId($request->getBody());
        if ($id === null) {
            $response->json(['error' => 'id required'], 400);
            return;
        }

        $success = $this->facts->deactivate($id, $chatId);

        $response->json(['success' => $success]);
    }

    #[Route('/api/meals/conflicts', 'GET')]
    public function getConflicts(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $response->json(['items' => $this->conflicts->getPending($chatId)]);
    }

    #[Route('/api/meals/conflicts/resolve', 'POST')]
    public function resolveConflict(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $body = $request->getBody();
        $id = $this->bodyId($body);
        if ($id === null) {
            $response->json(['error' => 'id required'], 400);
            return;
        }

        $action = is_string($body['action'] ?? null) ? $body['action'] : '';
        if (!in_array($action, self::RESOLVE_ACTIONS, true)) {
            $response->json(['error' => 'invalid action'], 400);
            return;
        }

        $success = $this->appService->resolveConflict($chatId, $id, $action);

        $response->json(['success' => $success]);
    }

    #[Route('/api/meals/history', 'GET')]
    public function getHistory(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $limit = (int) $request->getQueryParam('limit', 100);
        $limit = max(1, min(500, $limit));

        $response->json(['items' => $this->history->getAll($chatId, $limit)]);
    }

    #[Route('/api/meals/{path:.*}', 'OPTIONS')]
    public function cors(Request $request, Response $response): void
    {
        $response->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, X-Telegram-Init-Data')
            ->status(204);
    }

    private function guard(Request $request, Response $response): ?int
    {
        $requested = (int) $request->getQueryParam('chat_id', 0);
        $access = $this->auth->resolveAccess($request, $requested);
        if (!$access->granted) {
            $response->json(['error' => $access->denyError], $access->denyStatus);
            return null;
        }

        return $access->chatId;
    }

    private function isUniqueViolation(DatabaseException $e): bool
    {
        $prev = $e->getPrevious();

        return $prev instanceof \PDOException && $prev->getCode() === '23505';
    }

    private function bodyId(array $body): ?int
    {
        $id = (int) ($body['id'] ?? 0);

        return $id === 0 ? null : $id;
    }

    private function normalizeName(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $name = trim((string) $value);
        if ($name === '' || mb_strlen($name) > 200) {
            return null;
        }

        return $name;
    }

    private function normalizeFact(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $fact = trim($value);

        return $fact === '' ? null : $fact;
    }

    private function normalizeUnit(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return false;
        }

        $unit = trim((string) $value);
        if ($unit === '') {
            return null;
        }

        return mb_strlen($unit) > 30 ? false : $unit;
    }

    private function normalizeQuantity(mixed $value): float|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return false;
        }

        $quantity = (float) $value;

        return $quantity < 0 ? false : $quantity;
    }

    private function resolveAvailable(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return $this->toBool($value);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 't', 'on', 'yes'], true);
        }

        return (bool) $value;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
