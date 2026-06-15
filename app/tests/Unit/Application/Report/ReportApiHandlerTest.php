<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Report;

use App\Application\Report\DTO\ReportFilter;
use App\Application\Report\Http\Handler\ReportApiHandler;
use App\Application\Report\Http\Middleware\TelegramWebAppAuth;
use App\Application\Report\Repository\ReportRepository;
use App\Application\Report\Service\ReportService;
use App\Component\Telegram\WebApp\WebAppAccess;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;
use App\Service\Task\TaskManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

final class ReportApiHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface $reportService;
    private MockInterface $reportRepository;
    private MockInterface $auth;
    private MockInterface $taskManager;
    private ReportApiHandler $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportService = Mockery::mock(ReportService::class);
        $this->reportRepository = Mockery::mock(ReportRepository::class);
        $this->auth = Mockery::mock(TelegramWebAppAuth::class);
        $this->taskManager = Mockery::mock(TaskManager::class);

        $this->sut = new ReportApiHandler(
            $this->reportService,
            $this->reportRepository,
            $this->auth,
            $this->taskManager,
        );
    }

    private function grant(MockInterface $request, int $requested, int $chatId, array $user = ['id' => 0]): void
    {
        $request->shouldReceive('getQueryParam')->with('chat_id', 0)->andReturn($requested);
        $this->auth->shouldReceive('resolveAccess')
            ->once()
            ->with($request, $requested)
            ->andReturn(WebAppAccess::grant($chatId, $user));
    }

    private function deny(MockInterface $request, int $requested, int $status, string $error): void
    {
        $request->shouldReceive('getQueryParam')->with('chat_id', 0)->andReturn($requested);
        $this->auth->shouldReceive('resolveAccess')
            ->once()
            ->with($request, $requested)
            ->andReturn(WebAppAccess::deny($status, $error));
    }

    public function testSummaryReturnsUnauthorizedWhenAccessDenied(): void
    {
        $request = Mockery::mock(Request::class);
        $this->deny($request, 0, 401, 'Unauthorized');

        $this->reportService->shouldNotReceive('getSummary');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'Unauthorized'], 401)->andReturnSelf();

        $this->sut->summary($request, $response);
    }

    public function testSummaryReturnsForbiddenOnForeignChat(): void
    {
        $request = Mockery::mock(Request::class);
        $this->deny($request, 7, 403, 'Forbidden');

        $this->reportService->shouldNotReceive('getSummary');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'Forbidden'], 403)->andReturnSelf();

        $this->sut->summary($request, $response);
    }

    public function testSummaryUsesAuthoritativeChatId(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 999, 7);
        $request->shouldReceive('getQueryParams')->andReturn([]);

        $this->reportService->shouldReceive('getSummary')
            ->once()
            ->with(7, Mockery::type(ReportFilter::class))
            ->andReturn(['total' => 0]);

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['total' => 0])->andReturnSelf();

        $this->sut->summary($request, $response);
    }

    public function testTransactionsReturnsForbiddenOnForeignChat(): void
    {
        $request = Mockery::mock(Request::class);
        $this->deny($request, 7, 403, 'Forbidden');

        $this->reportService->shouldNotReceive('getTransactions');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'Forbidden'], 403)->andReturnSelf();

        $this->sut->transactions($request, $response);
    }

    public function testTransactionsUsesAuthoritativeChatId(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 999, 7);
        $request->shouldReceive('getQueryParams')->andReturn([]);

        $this->reportService->shouldReceive('getTransactions')
            ->once()
            ->with(7, Mockery::type(ReportFilter::class))
            ->andReturn([]);

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with([])->andReturnSelf();

        $this->sut->transactions($request, $response);
    }

    public function testDeleteTransactionReturnsUnauthorizedWhenAccessDenied(): void
    {
        $request = Mockery::mock(Request::class);
        $this->deny($request, 7, 401, 'Unauthorized');

        $this->reportRepository->shouldNotReceive('deleteTransaction');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'Unauthorized'], 401)->andReturnSelf();

        $this->sut->deleteTransaction($request, $response);
    }

    public function testDeleteTransactionRejectsZeroItemIndex(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 7, 7);
        $request->shouldReceive('getQueryParam')->with('message_id', 0)->andReturn(55);
        $request->shouldReceive('getQueryParam')->with('item_index', 0)->andReturn(0);

        $this->reportRepository->shouldNotReceive('deleteTransaction');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'message_id and item_index required'], 400)->andReturnSelf();

        $this->sut->deleteTransaction($request, $response);
    }

    public function testDeleteTransactionRejectsNegativeItemIndex(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 7, 7);
        $request->shouldReceive('getQueryParam')->with('message_id', 0)->andReturn(55);
        $request->shouldReceive('getQueryParam')->with('item_index', 0)->andReturn(-1);

        $this->reportRepository->shouldNotReceive('deleteTransaction');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'message_id and item_index required'], 400)->andReturnSelf();

        $this->sut->deleteTransaction($request, $response);
    }

    public function testDeleteTransactionPassesAuthoritativeChatIdToRepository(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 999, 7);
        $request->shouldReceive('getQueryParam')->with('message_id', 0)->andReturn(55);
        $request->shouldReceive('getQueryParam')->with('item_index', 0)->andReturn(2);

        $this->reportRepository->shouldReceive('deleteTransaction')
            ->once()
            ->with(55, 2, 7)
            ->andReturnTrue();

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['success' => true])->andReturnSelf();

        $this->sut->deleteTransaction($request, $response);
    }

    public function testUpdateTransactionReturnsForbiddenOnForeignChat(): void
    {
        $request = Mockery::mock(Request::class);
        $this->deny($request, 7, 403, 'Forbidden');

        $this->reportRepository->shouldNotReceive('updateTransaction');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'Forbidden'], 403)->andReturnSelf();

        $this->sut->updateTransaction($request, $response);
    }

    public function testUpdateTransactionRejectsZeroItemIndex(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 7, 7);
        $request->shouldReceive('getBody')->andReturn([
            'message_id' => 55,
            'item_index' => 0,
            'type' => 'expense',
            'amount' => 10,
            'currency' => 'THB',
            'category' => 'food',
        ]);

        $this->reportRepository->shouldNotReceive('updateTransaction');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'message_id and item_index required'], 400)->andReturnSelf();

        $this->sut->updateTransaction($request, $response);
    }

    public function testUpdateTransactionRejectsTooLongDescription(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 7, 7);
        $request->shouldReceive('getBody')->andReturn([
            'message_id' => 55,
            'item_index' => 2,
            'type' => 'expense',
            'amount' => 10,
            'currency' => 'THB',
            'category' => 'food',
            'description' => str_repeat('a', 1001),
        ]);

        $this->reportRepository->shouldNotReceive('updateTransaction');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'description too long'], 400)->andReturnSelf();

        $this->sut->updateTransaction($request, $response);
    }

    public function testUpdateTransactionRejectsTooLongCategory(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 7, 7);
        $request->shouldReceive('getBody')->andReturn([
            'message_id' => 55,
            'item_index' => 2,
            'type' => 'expense',
            'amount' => 10,
            'currency' => 'THB',
            'category' => str_repeat('c', 201),
        ]);

        $this->reportRepository->shouldNotReceive('updateTransaction');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'category too long'], 400)->andReturnSelf();

        $this->sut->updateTransaction($request, $response);
    }

    public function testUpdateTransactionPassesAuthoritativeChatIdToRepository(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 999, 7);
        $request->shouldReceive('getBody')->andReturn([
            'message_id' => 55,
            'item_index' => 2,
            'type' => 'expense',
            'amount' => 12.5,
            'currency' => 'THB',
            'category' => 'food',
            'description' => 'lunch',
            'wallet' => null,
        ]);

        $this->reportRepository->shouldReceive('updateTransaction')
            ->once()
            ->with(55, 2, 7, [
                'type' => 'expense',
                'amount' => 12.5,
                'currency' => 'THB',
                'category' => 'food',
                'description' => 'lunch',
                'wallet' => null,
            ])
            ->andReturnTrue();

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['success' => true])->andReturnSelf();

        $this->sut->updateTransaction($request, $response);
    }

    public function testCreateTransactionReturnsUnauthorizedWhenAccessDenied(): void
    {
        $request = Mockery::mock(Request::class);
        $this->deny($request, 0, 401, 'Unauthorized');

        $this->reportRepository->shouldNotReceive('createTransaction');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'Unauthorized'], 401)->andReturnSelf();

        $this->sut->createTransaction($request, $response);
    }

    public function testCreateTransactionReturnsForbiddenOnForeignChat(): void
    {
        $request = Mockery::mock(Request::class);
        $this->deny($request, 7, 403, 'Forbidden');

        $this->reportRepository->shouldNotReceive('createTransaction');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'Forbidden'], 403)->andReturnSelf();

        $this->sut->createTransaction($request, $response);
    }

    public function testCreateTransactionUsesAuthoritativeChatIdAndUser(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 999, 7, ['id' => 42, 'chat_id' => 7]);
        $request->shouldReceive('getBody')->andReturn([
            'type' => 'expense',
            'amount' => 9.99,
            'currency' => 'THB',
            'category' => 'food',
            'description' => 'snack',
            'wallet' => null,
        ]);
        $request->shouldReceive('getQueryParam')->with('topic_id')->andReturnNull();

        $this->reportRepository->shouldReceive('createTransaction')
            ->once()
            ->with(7, 42, [
                'type' => 'expense',
                'amount' => 9.99,
                'currency' => 'THB',
                'category' => 'food',
                'description' => 'snack',
                'wallet' => null,
            ], null)
            ->andReturn(123);

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['id' => 123, 'success' => true])->andReturnSelf();

        $this->sut->createTransaction($request, $response);
    }

    public function testCreateTransactionRejectsInvalidBody(): void
    {
        $request = Mockery::mock(Request::class);
        $this->grant($request, 7, 7, ['id' => 42]);
        $request->shouldReceive('getBody')->andReturn([
            'type' => 'expense',
            'amount' => 0,
            'currency' => 'THB',
            'category' => 'food',
        ]);

        $this->reportRepository->shouldNotReceive('createTransaction');

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->with(['error' => 'amount must be greater than 0'], 400)->andReturnSelf();

        $this->sut->createTransaction($request, $response);
    }
}
