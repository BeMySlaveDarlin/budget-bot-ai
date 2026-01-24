<?php

declare(strict_types=1);

namespace App\Component\LLM\DTO;

readonly class Usage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
        public int $cachedTokens = 0,
        public int $reasoningTokens = 0
    ) {
    }

    public static function fromArray(array $data): self
    {
        $inputTokens = $data['input_tokens'] ?? $data['prompt_tokens'] ?? 0;
        $outputTokens = $data['output_tokens'] ?? $data['completion_tokens'] ?? 0;
        $totalTokens = $data['total_tokens'] ?? ($inputTokens + $outputTokens);

        $cachedTokens = 0;
        $reasoningTokens = 0;

        if (isset($data['prompt_tokens_details']['cached_tokens'])) {
            $cachedTokens = (int) $data['prompt_tokens_details']['cached_tokens'];
        } elseif (isset($data['cache_read_input_tokens'])) {
            $cachedTokens = (int) $data['cache_read_input_tokens'];
        }

        if (isset($data['completion_tokens_details']['reasoning_tokens'])) {
            $reasoningTokens = (int) $data['completion_tokens_details']['reasoning_tokens'];
        } elseif (isset($data['thoughtsTokenCount'])) {
            $reasoningTokens = (int) $data['thoughtsTokenCount'];
        }

        return new self($inputTokens, $outputTokens, $totalTokens, $cachedTokens, $reasoningTokens);
    }

    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'cached_tokens' => $this->cachedTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
