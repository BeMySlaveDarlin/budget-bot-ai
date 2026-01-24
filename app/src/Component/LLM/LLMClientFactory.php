<?php

declare(strict_types=1);

namespace App\Component\LLM;

use App\Component\LLM\Repository\LlmProviderRepository;
use App\Component\LLM\Client\ClaudeClient;
use App\Component\LLM\Client\Contract\LLMClientInterface;
use App\Component\LLM\Client\OpenAIClient;
use App\Component\LLM\Exception\LLMException;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[Injectable]
class LLMClientFactory
{
    private array $clients = [];

    public function __construct(
        private readonly LlmProviderRepository $providerRepository,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function createByCode(string $code): LLMClientInterface
    {
        if (isset($this->clients[$code])) {
            return $this->clients[$code];
        }

        $provider = $this->providerRepository->findByCode($code);

        if ($provider === null) {
            throw LLMException::providerNotConfigured($code);
        }

        $client = $this->createClient($provider);
        $this->clients[$code] = $client;

        return $client;
    }

    public function createById(int $providerId): LLMClientInterface
    {
        $provider = $this->providerRepository->findById($providerId);

        if ($provider === null) {
            throw LLMException::providerNotConfigured("ID: {$providerId}");
        }

        $code = $provider['code'];

        if (isset($this->clients[$code])) {
            return $this->clients[$code];
        }

        $client = $this->createClient($provider);
        $this->clients[$code] = $client;

        return $client;
    }

    private function createClient(array $provider): LLMClientInterface
    {
        $code = $provider['code'];
        $apiKey = $this->getApiKey($provider);

        $config = $provider;
        if (!empty($provider['configuration'])) {
            $extraConfig = is_string($provider['configuration'])
                ? json_decode($provider['configuration'], true) ?? []
                : $provider['configuration'];
            $config = array_merge($config, $extraConfig);
        }

        $logger = $this->logger ?? new NullLogger();
        $endpoint = $provider['api_endpoint'] ?? '';

        return match (true) {
            str_contains($endpoint, 'anthropic.com'), $code === 'claude' => new ClaudeClient($config, $apiKey, $logger),
            str_contains($endpoint, 'openai.com'), $code === 'openai' => new OpenAIClient($config, $apiKey, $logger),
            default => throw LLMException::providerNotConfigured($code),
        };
    }

    private function getApiKey(array $provider): string
    {
        if (($provider['type'] ?? 'api') === 'local') {
            return '';
        }

        $envKeyName = $provider['env_key_name'] ?? null;

        if ($envKeyName === null) {
            throw LLMException::apiKeyMissing($provider['code'], 'env_key_name not configured');
        }

        $apiKey = getenv($envKeyName) ?: ($_ENV[$envKeyName] ?? '');

        if (empty($apiKey)) {
            throw LLMException::apiKeyMissing($provider['code'], $envKeyName);
        }

        return $apiKey;
    }

    public function getAvailableProviders(): array
    {
        $providers = $this->providerRepository->getActive();
        $available = [];

        foreach ($providers as $provider) {
            try {
                if (($provider['type'] ?? 'api') === 'local') {
                    $available[] = $provider;
                    continue;
                }

                $envKeyName = $provider['env_key_name'] ?? null;
                if ($envKeyName && !empty(getenv($envKeyName) ?: ($_ENV[$envKeyName] ?? ''))) {
                    $available[] = $provider;
                }
            } catch (\Throwable) {
            }
        }

        return $available;
    }

    public function healthCheck(string $code): array
    {
        try {
            $client = $this->createByCode($code);
            return $client->ping();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
