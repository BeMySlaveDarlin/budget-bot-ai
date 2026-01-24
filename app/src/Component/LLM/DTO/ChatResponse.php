<?php

declare(strict_types=1);

namespace App\Component\LLM\DTO;

readonly class ChatResponse
{
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public Usage $usage,
        public string $finishReason,
        public ?string $model = null,
        public ?string $id = null
    ) {
    }

    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    public function getFirstToolCall(): ?ToolCall
    {
        return $this->toolCalls[0] ?? null;
    }

    public function getToolCallByName(string $name): ?ToolCall
    {
        foreach ($this->toolCalls as $toolCall) {
            if ($toolCall->name === $name) {
                return $toolCall;
            }
        }
        return null;
    }

    public function asText(): string
    {
        return $this->content ?? '';
    }

    public function asArray(): ?array
    {
        if ($this->content === null) {
            return null;
        }

        return json_decode($this->content, true);
    }

    public function isComplete(): bool
    {
        return in_array($this->finishReason, ['stop', 'end_turn', 'tool_use'], true);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'model' => $this->model,
            'content' => $this->content,
            'tool_calls' => array_map(fn(ToolCall $tc) => $tc->toArray(), $this->toolCalls),
            'usage' => $this->usage->toArray(),
            'finish_reason' => $this->finishReason,
        ];
    }
}
