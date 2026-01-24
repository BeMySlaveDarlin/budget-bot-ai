<?php

declare(strict_types=1);

namespace App\Application\System\HealthCheck;

use App\Application\System\HealthCheck\Contract\HealthCheckInterface;
use App\Component\LLM\LLMClientFactory;
use DI\Attribute\Injectable;

#[Injectable]
class LlmHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private LLMClientFactory $llmFactory
    ) {
    }

    public function getName(): string
    {
        return 'llm';
    }

    public function check(): array
    {
        $result = $this->llmFactory->healthCheck('claude');

        if ($result['ok']) {
            return [
                'healthy' => true,
                'latency_ms' => $result['latency_ms'],
                'model' => $result['model'],
                'question' => $result['question'] ?? null,
                'response' => $result['response'],
            ];
        }

        return ['healthy' => false, 'error' => $result['error'] ?? 'Connection failed'];
    }
}
