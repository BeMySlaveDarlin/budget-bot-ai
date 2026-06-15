<?php

declare(strict_types=1);

namespace Tests\E2E\Meals;

use App\Application\Meals\Http\Handler\WebhookHandler;
use App\Application\Meals\Task\MealsWebhookProcessTask;
use App\Component\Telegram\WebhookTokenValidator;
use App\Service\Cache\CacheInterface;
use App\Service\Config\Config;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;
use App\Service\Task\TaskManager;
use DI\Container;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestContainerFactory;

final class MealsWebhookDedupTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Container $container;
    private CacheInterface $cache;
    private int $updateId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = TestContainerFactory::create();
        $this->cache = $this->container->get(CacheInterface::class);
        $this->updateId = random_int(900_000_000, 2_000_000_000);
        $this->cache->delete("meals:update:{$this->updateId}");
    }

    protected function tearDown(): void
    {
        $this->cache->delete("meals:update:{$this->updateId}");
        parent::tearDown();
    }

    public function testHandleDispatchesOnlyOnceForDuplicateUpdateId(): void
    {
        $token = (string) $this->container->get(Config::class)->get('telegram.meals_bot_token', '');
        $body = [
            'update_id' => $this->updateId,
            'message' => [
                'message_id' => 1,
                'text' => 'привет',
                'chat' => ['id' => -99990020, 'type' => 'private'],
                'from' => ['id' => -99990021],
            ],
        ];

        $taskManager = Mockery::mock(TaskManager::class);
        $taskManager->shouldReceive('dispatch')
            ->once()
            ->with(MealsWebhookProcessTask::class, ['update' => $body], $this->updateId, 'meals_update')
            ->andReturn(1);

        $handler = new WebhookHandler(
            $taskManager,
            $this->container->get(WebhookTokenValidator::class),
            $this->cache
        );

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getBody')->andReturn($body);

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->twice()->with(['ok' => true])->andReturnSelf();

        $handler->handle($request, $response, ['token' => $token]);
        $handler->handle($request, $response, ['token' => $token]);
    }
}
