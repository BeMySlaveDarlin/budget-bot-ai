<?php

declare(strict_types=1);

namespace Tests\E2E\Http;

use App\Service\Cache\CacheInterface;
use App\Service\Config\Config;
use App\Service\Database\DatabaseConnection;
use DI\Container;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\TestContainerFactory;

final class WebhookAuthTest extends TestCase
{
    private Container $container;
    private Client $http;
    private string $mealsToken;
    private string $budgetToken;
    private array $updateIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = TestContainerFactory::create();
        $config = $this->container->get(Config::class);
        $this->mealsToken = (string) $config->get('telegram.meals_bot_token', '');
        $this->budgetToken = (string) $config->get('telegram.budget_bot_token', '');

        $this->http = new Client([
            'base_uri' => getenv('TEST_BASE_URI') ?: 'http://127.0.0.1:9501',
            'http_errors' => false,
            'timeout' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        $db = $this->container->get(DatabaseConnection::class);
        $cache = $this->container->get(CacheInterface::class);

        foreach ($this->updateIds as $updateId) {
            $this->waitForTaskFinished($db, $updateId);
            $db->execute('DELETE FROM tasks WHERE context_id = ?', [$updateId]);
            $db->execute('DELETE FROM telegram_updates WHERE update_id = ?', [$updateId]);
            $cache->delete("meals:update:{$updateId}");
        }

        parent::tearDown();
    }

    public function testMealsWebhookWithValidTokenReturnsOk(): void
    {
        $response = $this->post("/telegram/meal/{$this->mealsToken}", $this->emptyUpdate());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], json_decode((string) $response->getBody(), true));
    }

    public function testMealsWebhookWithForgedTokenReturns403(): void
    {
        $response = $this->post('/telegram/meal/FORGED', $this->emptyUpdate());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['ok' => false], json_decode((string) $response->getBody(), true));
    }

    public function testBudgetWebhookWithValidTokenReturnsOk(): void
    {
        $response = $this->post("/telegram/budget/{$this->budgetToken}", $this->emptyUpdate());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], json_decode((string) $response->getBody(), true));
    }

    public function testBudgetWebhookWithWrongTokenReturns403(): void
    {
        $response = $this->post('/telegram/budget/WRONG', $this->emptyUpdate());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['ok' => false], json_decode((string) $response->getBody(), true));
    }

    public function testLegacyBudgetWebhookWithValidTokenReturnsOk(): void
    {
        $response = $this->post("/telegram/{$this->budgetToken}", $this->emptyUpdate());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], json_decode((string) $response->getBody(), true));
    }

    private function post(string $path, array $body): ResponseInterface
    {
        return $this->http->post($path, ['json' => $body]);
    }

    private function emptyUpdate(): array
    {
        $updateId = random_int(900_000_000, 2_000_000_000);
        $this->updateIds[] = $updateId;

        return ['update_id' => $updateId];
    }

    private function waitForTaskFinished(DatabaseConnection $db, int $updateId): void
    {
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            $task = $db->queryFirst(
                'SELECT status FROM tasks WHERE context_id = ? ORDER BY id DESC LIMIT 1',
                [$updateId]
            );

            if ($task === null || !in_array($task['status'], ['pending', 'processing'], true)) {
                return;
            }

            usleep(50_000);
        }
    }
}
