<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Meals\Http\Handler;

use App\Application\Meals\Http\Handler\MealAppApiHandler;
use App\Application\Meals\Http\Middleware\MealsWebAppAuth;
use App\Application\Meals\Repository\MealCookHistoryRepository;
use App\Application\Meals\Repository\MealFactConflictRepository;
use App\Application\Meals\Repository\MealFactRepository;
use App\Application\Meals\Repository\MealInventoryRepository;
use App\Application\Meals\Service\MealAppService;
use App\Component\Telegram\WebApp\WebAppAccess;
use App\Service\Database\DatabaseException;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

final class MealAppApiHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface $inventory;
    private MockInterface $facts;
    private MockInterface $history;
    private MockInterface $conflicts;
    private MockInterface $appService;
    private MockInterface $auth;
    private MealAppApiHandler $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = Mockery::mock(MealInventoryRepository::class);
        $this->facts = Mockery::mock(MealFactRepository::class);
        $this->history = Mockery::mock(MealCookHistoryRepository::class);
        $this->conflicts = Mockery::mock(MealFactConflictRepository::class);
        $this->appService = Mockery::mock(MealAppService::class);
        $this->auth = Mockery::mock(MealsWebAppAuth::class);

        $this->sut = new MealAppApiHandler(
            $this->inventory,
            $this->facts,
            $this->history,
            $this->conflicts,
            $this->appService,
            $this->auth,
        );
    }

    private function grant(MockInterface $request, int $chatId): void
    {
        $request->shouldReceive('getQueryParam')->with('chat_id', 0)->andReturn($chatId);
        $this->auth->shouldReceive('resolveAccess')
            ->once()
            ->with($request, $chatId)
            ->andReturn(WebAppAccess::grant($chatId, ['id' => 0, 'chat_id' => $chatId]));
    }

    private function uniqueViolation(): DatabaseException
    {
        $pdo = new class('duplicate key value violates unique constraint') extends \PDOException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '23505';
            }
        };

        return new DatabaseException('Insert failed: ' . $pdo->getMessage(), 0, $pdo);
    }

    public function testGetInventoryReturnsUnauthorizedWhenAccessDenied(): void
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getQueryParam')->with('chat_id', 0)->andReturn(0);
        $this->auth->shouldReceive('resolveAccess')
            ->once()
            ->with($request, 0)
            ->andReturn(WebAppAccess::deny(401, 'Unauthorized'));

        $this->inventory->shouldNotReceive('getForChatFull');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'Unauthorized'], 401)->andReturnSelf();

        $this->sut->getInventory($request, $response);
    }

    public function testGetInventoryReturnsBadRequestWhenChatIdMissing(): void
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getQueryParam')->with('chat_id', 0)->andReturn(0);
        $this->auth->shouldReceive('resolveAccess')
            ->once()
            ->with($request, 0)
            ->andReturn(WebAppAccess::deny(400, 'chat_id required'));

        $this->inventory->shouldNotReceive('getForChatFull');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'chat_id required'], 400)->andReturnSelf();

        $this->sut->getInventory($request, $response);
    }

    public function testGetInventoryReturnsForbiddenOnForeignChat(): void
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getQueryParam')->with('chat_id', 0)->andReturn(7);
        $this->auth->shouldReceive('resolveAccess')
            ->once()
            ->with($request, 7)
            ->andReturn(WebAppAccess::deny(403, 'Forbidden'));

        $this->inventory->shouldNotReceive('getForChatFull');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'Forbidden'], 403)->andReturnSelf();

        $this->sut->getInventory($request, $response);
    }

    public function testGetInventoryReturnsMappedItems(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 7);

        $this->inventory->shouldReceive('getForChatFull')
            ->once()
            ->with(7)
            ->andReturn([
                [
                    'id' => '5',
                    'name' => 'eggs',
                    'available' => 't',
                    'quantity' => '12',
                    'unit' => 'pcs',
                    'updated_at' => '2026-06-12',
                ],
            ]);

        $captured = [];
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(Mockery::capture($captured))->andReturnSelf();

        $this->sut->getInventory($request, $response);

        $this->assertSame(
            [
                'items' => [
                    [
                        'id' => 5,
                        'name' => 'eggs',
                        'available' => true,
                        'quantity' => 12.0,
                        'unit' => 'pcs',
                        'updated_at' => '2026-06-12',
                    ],
                ],
            ],
            $captured
        );
    }

    public function testCreateInventoryRejectsEmptyName(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 42);
        $request->shouldReceive('getBody')->andReturn(['name' => '   ']);

        $this->inventory->shouldNotReceive('create');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'name required'], 400)->andReturnSelf();

        $this->sut->createInventory($request, $response);
    }

    public function testCreateInventoryReturnsConflictWhenItemExists(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 42);
        $request->shouldReceive('getBody')->andReturn(['name' => 'milk']);

        $this->inventory->shouldReceive('existsByName')->once()->with(42, 'milk')->andReturnTrue();
        $this->inventory->shouldNotReceive('create');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'item already exists'], 409)->andReturnSelf();

        $this->sut->createInventory($request, $response);
    }

    public function testCreateInventoryCreatesItem(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 42);
        $request->shouldReceive('getBody')->andReturn([
            'name' => 'milk',
            'unit' => 'l',
            'quantity' => '2',
            'available' => true,
        ]);

        $this->inventory->shouldReceive('existsByName')->once()->with(42, 'milk')->andReturnFalse();
        $this->inventory->shouldReceive('create')
            ->once()
            ->with(42, 'milk', true, 2.0, 'l')
            ->andReturn(42);

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['id' => 42, 'success' => true])->andReturnSelf();

        $this->sut->createInventory($request, $response);
    }

    public function testCreateInventoryReturnsConflictOnUniqueViolation(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 42);
        $request->shouldReceive('getBody')->andReturn(['name' => 'milk']);

        $this->inventory->shouldReceive('existsByName')->once()->with(42, 'milk')->andReturnFalse();
        $this->inventory->shouldReceive('create')
            ->once()
            ->with(42, 'milk', true, null, null)
            ->andThrow($this->uniqueViolation());

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'item already exists'], 409)->andReturnSelf();

        $this->sut->createInventory($request, $response);
    }

    public function testUpdateInventoryReturnsConflictOnUniqueViolation(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 42);
        $request->shouldReceive('getBody')->andReturn(['id' => 5, 'name' => 'milk']);

        $this->inventory->shouldReceive('update')
            ->once()
            ->with(5, 42, 'milk', true, null, null)
            ->andThrow($this->uniqueViolation());

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'item already exists'], 409)->andReturnSelf();

        $this->sut->updateInventory($request, $response);
    }

    public function testResolveConflictRejectsInvalidAction(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 42);
        $request->shouldReceive('getBody')->andReturn(['id' => 7, 'action' => 'bad']);

        $this->appService->shouldNotReceive('resolveConflict');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'invalid action'], 400)->andReturnSelf();

        $this->sut->resolveConflict($request, $response);
    }

    public function testResolveConflictRejectsMissingId(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 42);
        $request->shouldReceive('getBody')->andReturn(['action' => 'accept_new']);

        $this->appService->shouldNotReceive('resolveConflict');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'id required'], 400)->andReturnSelf();

        $this->sut->resolveConflict($request, $response);
    }

    public function testResolveConflictDelegatesValidAction(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 42);
        $request->shouldReceive('getBody')->andReturn(['id' => 7, 'action' => 'accept_new']);

        $this->appService->shouldReceive('resolveConflict')
            ->once()
            ->with(42, 7, 'accept_new')
            ->andReturnTrue();

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['success' => true])->andReturnSelf();

        $this->sut->resolveConflict($request, $response);
    }
}
