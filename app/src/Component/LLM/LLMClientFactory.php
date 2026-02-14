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
        $logger = $this->logger ?? new NullLogger();

        if (isset($this->clients[$code])) {
            $logger->debug('[LLMFactory] Returning cached client', ['code' => $code]);
            return $this->clients[$code];
        }

        $logger->info('[LLMFactory] Creating client', ['code' => $code]);

        $provider = $this->providerRepository->findByCode($code);

        if ($provider === null) {
            $logger->error('[LLMFactory] Provider not found in DB', ['code' => $code]);
            throw LLMException::providerNotConfigured($code);
        }

        $logger->info('[LLMFactory] Provider found', [
            'code' => $code,
            'model_name' => $provider['model_name'] ?? 'N/A',
            'api_endpoint' => $provider['api_endpoint'] ?? 'N/A',
            'env_key_name' => $provider['env_key_name'] ?? 'N/A',
            'is_active' => $provider['is_active'] ?? false,
        ]);

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
        $logger = $this->logger ?? new NullLogger();

        if (($provider['type'] ?? 'api') === 'local') {
            $logger->debug('[LLMFactory] Local provider, no API key needed');
            return '';
        }

        $envKeyName = $provider['env_key_name'] ?? null;

        if ($envKeyName === null) {
            $logger->error('[LLMFactory] env_key_name not configured', ['code' => $provider['code']]);
            throw LLMException::apiKeyMissing($provider['code'], 'env_key_name not configured');
        }

        $apiKey = getenv($envKeyName) ?: ($_ENV[$envKeyName] ?? '');

        if (empty($apiKey)) {
            $logger->error('[LLMFactory] API key is empty', [
                'code' => $provider['code'],
                'env_key_name' => $envKeyName,
            ]);
            throw LLMException::apiKeyMissing($provider['code'], $envKeyName);
        }

        $logger->debug('[LLMFactory] API key resolved', [
            'code' => $provider['code'],
            'key_length' => strlen($apiKey),
            'key_prefix' => substr($apiKey, 0, 8) . '...',
        ]);

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
