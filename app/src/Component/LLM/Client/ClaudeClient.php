<?php

declare(strict_types=1);

namespace App\Component\LLM\Client;

use App\Component\LLM\DTO\ChatRequest;
use App\Component\LLM\DTO\ChatResponse;
use App\Component\LLM\DTO\Message;
use App\Component\LLM\DTO\ToolCall;
use App\Component\LLM\DTO\ToolDefinition;
use App\Component\LLM\DTO\Usage;

class ClaudeClient extends AbstractLLMClient
{
    private const string API_VERSION = '2023-06-01';

    public function getProviderCode(): string
    {
        return 'claude';
    }

    protected function getEndpoint(): string
    {
        return $this->config['api_endpoint'] ?? 'https://api.anthropic.com/v1/messages';
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept-Encoding' => 'identity',
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
        ];
    }

    protected function buildRequestBody(ChatRequest $request): array
    {
        $body = [
            'model' => $request->model ?? $this->getDefaultModel(),
            'max_tokens' => $this->getMaxTokens($request),
            'messages' => $this->formatMessages($request),
        ];

        $systemMessage = $request->getSystemMessage();
        if ($systemMessage !== null) {
            $body['system'] = $systemMessage->content;
        }

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        if (!empty($request->tools)) {
            $body['tools'] = $this->formatTools($request->tools);
            $body['tool_choice'] = match ((string) $request->getOption('tool_choice', 'auto')) {
                'required' => ['type' => 'any'],
                'none' => ['type' => 'none'],
                default => ['type' => 'auto'],
            };
        }

        return $body;
    }

    protected function buildPingBody(string $question): array
    {
        return [
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 10,
            'messages' => [
                ['role' => 'user', 'content' => $question],
            ],
        ];
    }

    protected function extractPingResponse(array $data): string
    {
        return $data['content'][0]['text'] ?? '';
    }

    protected function parseResponse(array $response): ChatResponse
    {
        $content = null;
        $toolCalls = [];

        $this->logger->debug('[Claude:parseResponse] Raw response keys', [
            'keys' => array_keys($response),
            'content_blocks' => count($response['content'] ?? []),
            'stop_reason' => $response['stop_reason'] ?? 'N/A',
            'model' => $response['model'] ?? 'N/A',
        ]);

        foreach ($response['content'] ?? [] as $i => $block) {
            $this->logger->debug('[Claude:parseResponse] Content block', [
                'index' => $i,
                'type' => $block['type'] ?? 'unknown',
                'has_text' => isset($block['text']),
                'has_input' => isset($block['input']),
                'tool_name' => $block['name'] ?? null,
            ]);

            if ($block['type'] === 'text') {
                $content = $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $inputItems = $block['input']['items'] ?? [];
                $this->logger->info('[Claude:parseResponse] tool_use block', [
                    'tool_id' => $block['id'],
                    'tool_name' => $block['name'],
                    'input_keys' => array_keys($block['input'] ?? []),
                    'items_count' => count($inputItems),
                ]);

                $toolCalls[] = new ToolCall(
                    $block['id'],
                    $block['name'],
                    $block['input'] ?? []
                );
            }
        }

        $usage = new Usage(
            $response['usage']['input_tokens'] ?? 0,
            $response['usage']['output_tokens'] ?? 0,
            ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0)
        );

        return new ChatResponse(
            $content,
            $toolCalls,
            $usage,
            $response['stop_reason'] ?? 'unknown',
            $response['model'] ?? null,
            $response['id'] ?? null
        );
    }

    private function formatMessages(ChatRequest $request): array
    {
        $messages = [];

        foreach ($request->getNonSystemMessages() as $message) {
            if ($message instanceof Message) {
                $messages[] = [
                    'role' => $message->role === 'tool' ? 'user' : $message->role,
                    'content' => $this->formatMessageContent($message),
                ];
            }
        }

        return $messages;
    }

    private function formatMessageContent(Message $message): string|array
    {
        if ($message->role === 'tool' && $message->toolCallId !== null) {
            return [
                [
                    'type' => 'tool_result',
                    'tool_use_id' => $message->toolCallId,
                    'content' => $message->content,
                ],
            ];
        }

        return $message->content;
    }

    private function formatTools(array $tools): array
    {
        return array_map(function ($tool) {
            if ($tool instanceof ToolDefinition) {
                return $tool->toClaudeFormat();
            }
            return $tool;
        }, $tools);
    }
}
