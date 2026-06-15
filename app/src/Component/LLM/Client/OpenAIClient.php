<?php

declare(strict_types=1);

namespace App\Component\LLM\Client;

use App\Component\LLM\DTO\ChatRequest;
use App\Component\LLM\DTO\ChatResponse;
use App\Component\LLM\DTO\Message;
use App\Component\LLM\DTO\ToolCall;
use App\Component\LLM\DTO\ToolDefinition;
use App\Component\LLM\DTO\Usage;

class OpenAIClient extends AbstractLLMClient
{
    public function getProviderCode(): string
    {
        return 'openai';
    }

    protected function getEndpoint(): string
    {
        return $this->config['api_endpoint'] ?? 'https://api.openai.com/v1/chat/completions';
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
    }

    protected function buildRequestBody(ChatRequest $request): array
    {
        $model = $request->model ?? $this->getDefaultModel();

        $body = [
            'model' => $model,
            'messages' => $this->formatMessages($request),
        ];

        if ($this->usesCompletionTokens($model)) {
            $body['max_completion_tokens'] = $this->getMaxTokens($request);
        } else {
            $body['max_tokens'] = $this->getMaxTokens($request);
        }

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        if (!empty($request->tools)) {
            $body['tools'] = $this->formatTools($request->tools);
            $body['tool_choice'] = 'required';
        }

        if ($request->getOption('json_mode') === true) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return $body;
    }

    protected function buildPingBody(string $question): array
    {
        $model = $this->getDefaultModel() ?: 'gpt-4o-mini';

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $question],
            ],
        ];

        if ($this->usesCompletionTokens($model)) {
            $body['max_completion_tokens'] = 16;
        } else {
            $body['max_tokens'] = 16;
        }

        return $body;
    }

    protected function extractPingResponse(array $data): string
    {
        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function usesCompletionTokens(string $model): bool
    {
        $slashPos = strrpos($model, '/');
        $name = $slashPos === false ? $model : substr($model, $slashPos + 1);

        return str_starts_with($name, 'gpt-5')
            || str_starts_with($name, 'o1')
            || str_starts_with($name, 'o3');
    }

    protected function parseResponse(array $response): ChatResponse
    {
        $choice = $response['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $content = $message['content'] ?? null;
        $toolCalls = [];

        foreach ($message['tool_calls'] ?? [] as $call) {
            $toolCalls[] = ToolCall::fromArray($call);
        }

        $usage = Usage::fromArray($response['usage'] ?? []);

        return new ChatResponse(
            $content,
            $toolCalls,
            $usage,
            $choice['finish_reason'] ?? 'unknown',
            $response['model'] ?? null,
            $response['id'] ?? null
        );
    }

    private function formatMessages(ChatRequest $request): array
    {
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message instanceof Message) {
                $formatted = [
                    'role' => $message->role,
                    'content' => $message->content,
                ];

                if ($message->name !== null) {
                    $formatted['name'] = $message->name;
                }

                if ($message->toolCallId !== null) {
                    $formatted['tool_call_id'] = $message->toolCallId;
                }

                $messages[] = $formatted;
            }
        }

        return $messages;
    }

    private function formatTools(array $tools): array
    {
        return array_map(function ($tool) {
            if ($tool instanceof ToolDefinition) {
                return $tool->toOpenAIFormat();
            }
            return $tool;
        }, $tools);
    }
}
