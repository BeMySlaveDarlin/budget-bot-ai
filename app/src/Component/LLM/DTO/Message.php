<?php

declare(strict_types=1);

namespace App\Component\LLM\DTO;

readonly class Message
{
    public function __construct(
        public string $role,
        public string $content,
        public ?string $name = null,
        public ?string $toolCallId = null
    ) {
    }

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    public static function tool(string $content, string $toolCallId, ?string $name = null): self
    {
        return new self('tool', $content, $name, $toolCallId);
    }

    public function isSystem(): bool
    {
        return $this->role === 'system';
    }

    public function toArray(): array
    {
        $data = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }

        if ($this->toolCallId !== null) {
            $data['tool_call_id'] = $this->toolCallId;
        }

        return $data;
    }
}
