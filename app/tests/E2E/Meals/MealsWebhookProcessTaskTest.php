<?php

declare(strict_types=1);

namespace Tests\E2E\Meals;

use App\Application\Meals\Repository\BotConfigRepository;
use App\Application\Meals\Task\MealsWebhookProcessTask;
use App\Component\LLM\Client\Contract\LLMClientInterface;
use App\Component\LLM\DTO\ChatRequest;
use App\Component\LLM\DTO\ChatResponse;
use App\Component\LLM\DTO\Usage;
use App\Component\LLM\Exception\LLMException;
use App\Component\LLM\LLMClientFactory;
use App\Component\LLM\Repository\LlmUsageRepository;
use App\Component\Telegram\TelegramClient;
use App\Service\Cache\CacheInterface;
use App\Service\Config\Config;
use App\Service\Database\DatabaseConnection;
use DI\Container;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestContainerFactory;

final class MealsWebhookProcessTaskTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const string FALLBACK_REPLY = 'Не получилось обработать сообщение, попробуй ещё раз.';
    private const int TG_CHAT_ID = -99990010;
    private const int TG_USER_ID = -99990011;

    private Container $container;
    private DatabaseConnection $db;
    private MockInterface $llmClient;
    private MockInterface $usage;
    private MockInterface $telegram;
    private string $mealsToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = TestContainerFactory::create();
        $this->db = $this->container->get(DatabaseConnection::class);
        $this->mealsToken = (string) $this->container->get(Config::class)->get('telegram.meals_bot_token', '');

        $this->llmClient = Mockery::mock(LLMClientInterface::class);

        $factory = Mockery::mock(LLMClientFactory::class);
        $factory->shouldReceive('createById')->andReturn($this->llmClient)->byDefault();
        $factory->shouldReceive('createByCode')->andReturn($this->llmClient)->byDefault();
        $this->container->set(LLMClientFactory::class, $factory);

        $this->usage = Mockery::mock(LlmUsageRepository::class);
        $this->container->set(LlmUsageRepository::class, $this->usage);

        $this->telegram = Mockery::mock(TelegramClient::class);
        $this->container->set(TelegramClient::class, $this->telegram);

        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    public function testHandleHappyPathSavesMessagesAndSendsReply(): void
    {
        $reply = 'Предлагаю пасту с грибами: всё нужное есть в холодильнике.';

        $this->llmClient->shouldReceive('chat')
            ->once()
            ->with(Mockery::type(ChatRequest::class))
            ->andReturn($this->llmResponse($reply));

        $this->usage->shouldReceive('logUsage')->once()->with(Mockery::type('string'), 10, 5);
        $this->expectTelegramSend($reply);

        $result = $this->runTask($this->update('что приготовить на ужин?'));

        $this->assertSame(['status' => 'ok', 'type' => 'message'], $result);

        $chat = $this->db->queryFirst(
            'SELECT id FROM telegram_chats WHERE telegram_chat_id = ?',
            [self::TG_CHAT_ID]
        );
        $this->assertNotNull($chat);

        $user = $this->db->queryFirst(
            'SELECT id FROM telegram_users WHERE telegram_id = ?',
            [self::TG_USER_ID]
        );
        $this->assertNotNull($user);

        $messages = $this->fetchMealMessages();
        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('что приготовить на ужин?', $messages[0]['content']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame($reply, $messages[1]['content']);
    }

    public function testHandleLlmExceptionSendsFallbackAndKeepsUserMessage(): void
    {
        $this->llmClient->shouldReceive('chat')
            ->once()
            ->andThrow(new LLMException('upstream unavailable', 'test-provider'));

        $this->usage->shouldNotReceive('logUsage');
        $this->expectTelegramSend(self::FALLBACK_REPLY);

        $result = $this->runTask($this->update('что на обед?'));

        $this->assertSame(['status' => 'ok', 'type' => 'message'], $result);

        $messages = $this->fetchMealMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('что на обед?', $messages[0]['content']);
    }

    public function testHandleEmptyLlmReplySendsFallbackWithoutAssistantMessage(): void
    {
        $this->llmClient->shouldReceive('chat')
            ->once()
            ->andReturn($this->llmResponse(''));

        $this->usage->shouldReceive('logUsage')->once();
        $this->expectTelegramSend(self::FALLBACK_REPLY);

        $result = $this->runTask($this->update('посоветуй завтрак'));

        $this->assertSame(['status' => 'ok', 'type' => 'message'], $result);

        $messages = $this->fetchMealMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
    }

    public function testHandleTruncatesLongUserMessageTo4096(): void
    {
        $longText = str_repeat('а', 5000);
        $expected = str_repeat('а', 4096);
        $reply = 'Понял.';

        $captured = null;
        $this->llmClient->shouldReceive('chat')
            ->once()
            ->with(Mockery::on(function (ChatRequest $request) use (&$captured): bool {
                $captured = $request;

                return true;
            }))
            ->andReturn($this->llmResponse($reply));

        $this->usage->shouldReceive('logUsage')->once();
        $this->expectTelegramSend($reply);

        $this->runTask($this->update($longText));

        $this->assertInstanceOf(ChatRequest::class, $captured);
        $context = json_decode($captured->messages[1]->content, true);
        $this->assertSame($expected, $context['user_message']);

        $messages = $this->fetchMealMessages();
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame($expected, $messages[0]['content']);
    }

    public function testIgnoresMessageFromTopicOtherThanBound(): void
    {
        $this->container->get(BotConfigRepository::class)->setBoundTopicId('meals', 555);

        $this->llmClient->shouldNotReceive('chat');
        $this->telegram->shouldNotReceive('sendMessage');

        $result = $this->runTask($this->update('что приготовить?'));

        $this->assertSame(['status' => 'ok', 'type' => 'ignored_topic'], $result);
    }

    private function runTask(array $update): mixed
    {
        $task = MealsWebhookProcessTask::fromPayload(['update' => $update]);
        $task->setContainer($this->container);

        return $task->handle();
    }

    private function update(string $text): array
    {
        return [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1,
                'text' => $text,
                'chat' => ['id' => self::TG_CHAT_ID, 'type' => 'private', 'title' => 'phpunit-meals'],
                'from' => ['id' => self::TG_USER_ID, 'username' => 'phpunit', 'first_name' => 'PHPUnit'],
            ],
        ];
    }

    private function llmResponse(string $content): ChatResponse
    {
        return new ChatResponse($content, [], new Usage(10, 5, 15), 'stop');
    }

    private function expectTelegramSend(string $text): void
    {
        $this->telegram->shouldReceive('withToken')
            ->once()
            ->with($this->mealsToken)
            ->andReturnSelf();
        $this->telegram->shouldReceive('splitMessage')
            ->once()
            ->with($text)
            ->andReturn([$text]);
        $this->telegram->shouldReceive('sendMessage')
            ->once()
            ->with(self::TG_CHAT_ID, $text, 'HTML', null)
            ->andReturn(['ok' => true]);
    }

    private function fetchMealMessages(): array
    {
        return $this->db->query(
            'SELECT role, content FROM meal_messages
             WHERE chat_id IN (SELECT id FROM telegram_chats WHERE telegram_chat_id = ?)
             ORDER BY id',
            [self::TG_CHAT_ID]
        );
    }

    private function cleanupTestData(): void
    {
        $this->container->get(BotConfigRepository::class)->setBoundTopicId('meals', null);

        $chat = $this->db->queryFirst(
            'SELECT id FROM telegram_chats WHERE telegram_chat_id = ?',
            [self::TG_CHAT_ID]
        );

        if ($chat !== null) {
            $this->container->get(CacheInterface::class)->delete("meals:session:{$chat['id']}");
        }

        $this->db->execute(
            'DELETE FROM meal_messages WHERE chat_id IN (SELECT id FROM telegram_chats WHERE telegram_chat_id = ?)',
            [self::TG_CHAT_ID]
        );
        $this->db->execute(
            'DELETE FROM telegram_chat_users WHERE chat_id IN (SELECT id FROM telegram_chats WHERE telegram_chat_id = ?)',
            [self::TG_CHAT_ID]
        );
        $this->db->execute('DELETE FROM telegram_chats WHERE telegram_chat_id = ?', [self::TG_CHAT_ID]);
        $this->db->execute('DELETE FROM telegram_users WHERE telegram_id = ?', [self::TG_USER_ID]);
    }
}
