<?php

declare(strict_types=1);

namespace App\Application\Budget\Service;

use App\Component\LLM\Client\Contract\LLMClientInterface;
use App\Component\LLM\DTO\ChatRequest;
use App\Component\LLM\DTO\Message;
use App\Component\LLM\Exception\TokenLimitExceededException;
use App\Component\LLM\LLMClientFactory;
use App\Component\LLM\Repository\LlmUsageRepository;
use App\Component\Telegram\Repository\MessageRepository;
use App\Service\Config\Config;
use App\Service\Settings\Repository\ChatPromptRepository;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;

#[Injectable]
class AiService
{
    private LLMClientInterface $llmClient;

    public function __construct(
        private MessageRepository $messageRepo,
        private ChatPromptRepository $promptRepo,
        private LLMClientFactory $llmFactory,
        private LlmUsageRepository $usageRepo,
        private Config $config,
        private LoggerInterface $logger
    ) {
        $this->llmClient = $this->llmFactory->createByCode($this->config->get('llm.default_provider', 'claude'));
    }

    public function ask(int $chatId, string $question, int $months, string $currency = 'THB', ?int $topicId = null): array
    {
        $this->checkTokenLimit();

        $messages = $this->messageRepo->getForChat($chatId, $months, 1, $topicId);
        $systemPrompt = $this->buildSystemPrompt($chatId, $currency, $topicId);
        $context = $this->formatMessagesAsJson($messages);

        $userContent = json_encode([
            'period_months' => $months,
            'messages' => $context,
            'question' => $question,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $request = ChatRequest::create([
                Message::system($systemPrompt),
                Message::user($userContent),
            ]);

            $response = $this->llmClient->chat($request);

            $this->usageRepo->logUsage(
                $this->llmClient->getProviderCode(),
                $response->usage->inputTokens,
                $response->usage->outputTokens
            );

            return [
                'text' => $response->asText(),
                'tokens' => $response->usage->totalTokens,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('AI request failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'chat_id' => $chatId,
            ]);

            return [
                'text' => "Ошибка AI: " . $e->getMessage(),
                'tokens' => 0,
            ];
        }
    }

    private function buildSystemPrompt(int $chatId, string $currency, ?int $topicId = null): string
    {
        $globalPrompt = $this->config->get('llm.prompts.system', '');
        $customPrompt = $this->promptRepo->getPrompt($chatId, 'ai', $topicId);
        $taskPrompt = $customPrompt ?: $this->config->get('llm.prompts.ai_assistant', '');
        $taskPrompt = str_replace('{currency}', $currency, $taskPrompt);

        return $globalPrompt . "\n\n" . $taskPrompt;
    }

    private function formatMessagesAsJson(array $messages): array
    {
        if (empty($messages)) {
            return [];
        }

        $result = [];
        foreach ($messages as $msg) {
            $result[] = [
                'date' => date('Y-m-d', strtotime($msg['created_at'])),
                'message' => $msg['raw_text'],
            ];
        }

        return $result;
    }

    private function checkTokenLimit(): void
    {
        $limit = $this->config->get('llm.daily_token_limit', 1000000);
        $used = $this->usageRepo->getDailyUsage();

        if ($used >= $limit) {
            throw new TokenLimitExceededException($used, $limit);
        }
    }
}
