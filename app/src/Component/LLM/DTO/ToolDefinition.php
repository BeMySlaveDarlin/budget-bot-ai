<?php

declare(strict_types=1);

namespace App\Component\LLM\DTO;

readonly class ToolDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema
    ) {
    }

    public function toClaudeFormat(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
        ];
    }

    public function toOpenAIFormat(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->inputSchema,
            ],
        ];
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
        ];
    }
}
