<?php

declare(strict_types=1);

namespace App\Component\LLM\DTO;

readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments
    ) {
    }

    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? '';
        $name = $data['name'] ?? $data['function']['name'] ?? '';
        $arguments = $data['input'] ?? [];

        if (isset($data['function']['arguments'])) {
            $args = $data['function']['arguments'];
            $arguments = is_string($args) ? json_decode($args, true) ?? [] : $args;
        }

        return new self($id, $name, $arguments);
    }

    public function getArgument(string $key, mixed $default = null): mixed
    {
        return $this->arguments[$key] ?? $default;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
        ];
    }
}
