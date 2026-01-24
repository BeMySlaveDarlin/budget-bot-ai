<?php

declare(strict_types=1);

namespace App\Service\Console\Handler;

use App\Component\LLM\Repository\LlmProviderRepository;
use App\Component\LLM\LLMClientFactory;
use App\Service\Attribute\Command;
use App\Service\Console\Contract\CommandInterface;

#[Command(name: 'llm:test', description: 'Test LLM provider connection')]
class LlmTestCommand implements CommandInterface
{
    public function __construct(
        private LLMClientFactory $clientFactory,
        private LlmProviderRepository $llmProviderRepo
    ) {
    }

    public function execute(array $args = []): int
    {
        $provider = $args[0] ?? 'claude';

        echo "Testing LLM provider: {$provider}\n";
        echo str_repeat('-', 40) . "\n";

        return $this->testProvider($provider);
    }

    private function testProvider(string $code): int
    {
        try {
            $result = $this->clientFactory->healthCheck($code);

            if ($result['ok']) {
                echo "API Key: configured\n";
                echo "Model: {$result['model']}\n";
                echo "Question: {$result['question']}\n";
                echo "Response: {$result['response']}\n";
                echo "Latency: {$result['latency_ms']}ms\n";
                echo "Input tokens: {$result['input_tokens']}\n";
                echo "Output tokens: {$result['output_tokens']}\n";
                echo str_repeat('-', 40) . "\n";
                echo strtoupper($code) . " API: OK\n";
                $this->updateHealthStatus($code, 'healthy');
                return 0;
            }

            echo "Error: {$result['error']}\n";
            $this->updateHealthStatus($code, 'unhealthy');
            return 1;
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            $this->updateHealthStatus($code, 'unhealthy');
            return 1;
        }
    }

    private function updateHealthStatus(string $providerCode, string $status): void
    {
        try {
            $provider = $this->llmProviderRepo->findByCode($providerCode);
            if ($provider) {
                $this->llmProviderRepo->updateHealthStatus($provider['id'], $status);
                echo "Health status updated: {$status}\n";
            }
        } catch (\Throwable $e) {
            echo "Warning: Could not update health status - " . $e->getMessage() . "\n";
        }
    }

    public function getName(): string
    {
        return 'llm:test';
    }

    public function getDescription(): string
    {
        return 'Test LLM provider connection';
    }
}
