<?php

declare(strict_types=1);

namespace App\Component\LLM\DTO;

readonly class ChatRequest
{
    public function __construct(
        public array $messages,
        public ?string $model = null,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public array $tools = [],
        public array $options = []
    ) {
    }

    public static function create(array $messages): self
    {
        return new self($messages);
    }

    public function withModel(string $model): self
    {
        return new self(
            $this->messages,
            $model,
            $this->maxTokens,
            $this->temperature,
            $this->tools,
            $this->options
        );
    }

    public function withMaxTokens(int $maxTokens): self
    {
        return new self(
            $this->messages,
            $this->model,
            $maxTokens,
            $this->temperature,
            $this->tools,
            $this->options
        );
    }

    public function withTemperature(float $temperature): self
    {
        return new self(
            $this->messages,
            $this->model,
            $this->maxTokens,
            $temperature,
            $this->tools,
            $this->options
        );
    }

    public function withTools(array $tools): self
    {
        return new self(
            $this->messages,
            $this->model,
            $this->maxTokens,
            $this->temperature,
            $tools,
            $this->options
        );
    }

    public function withOption(string $key, mixed $value): self
    {
        $options = $this->options;
        $options[$key] = $value;

        return new self(
            $this->messages,
            $this->model,
            $this->maxTokens,
            $this->temperature,
            $this->tools,
            $options
        );
    }

    public function getSystemMessage(): ?Message
    {
        foreach ($this->messages as $message) {
            if ($message instanceof Message && $message->isSystem()) {
                return $message;
            }
        }
        return null;
    }

    public function getNonSystemMessages(): array
    {
        return array_filter(
            $this->messages,
            fn($m) => !($m instanceof Message && $m->isSystem())
        );
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
