<?php

declare(strict_types=1);

namespace App\Component\LLM\Client\Contract;

use App\Component\LLM\DTO\ChatRequest;
use App\Component\LLM\DTO\ChatResponse;

interface LLMClientInterface
{
    public function chat(ChatRequest $request): ChatResponse;

    public function getProviderCode(): string;

    public function getDefaultModel(): string;

    public function supportsTools(): bool;
}
