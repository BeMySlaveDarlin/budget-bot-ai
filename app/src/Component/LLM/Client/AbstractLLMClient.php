<?php

declare(strict_types=1);

namespace App\Component\LLM\Client;

use App\Component\LLM\Client\Contract\LLMClientInterface;
use App\Component\LLM\DTO\ChatRequest;
use App\Component\LLM\DTO\ChatResponse;
use App\Component\LLM\DTO\Message;
use App\Component\LLM\Exception\AuthException;
use App\Component\LLM\Exception\LLMException;
use App\Component\LLM\Exception\RateLimitException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class AbstractLLMClient implements LLMClientInterface
{
    protected const int DEFAULT_TIMEOUT = 180;
    protected const int MAX_RETRIES = 2;
    protected const int RETRY_DELAY_MS = 1000;

    protected Client $httpClient;
    protected LoggerInterface $logger;

    public function __construct(
        protected readonly array $config,
        protected readonly string $apiKey,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->httpClient = $this->createHttpClient();
    }

    abstract public function getProviderCode(): string;

    abstract protected function getEndpoint(): string;

    abstract protected function getHeaders(): array;

    abstract protected function buildRequestBody(ChatRequest $request): array;

    abstract protected function parseResponse(array $response): ChatResponse;

    public function chat(ChatRequest $request): ChatResponse
    {
        $startTime = microtime(true);
        $model = $request->model ?? $this->getDefaultModel();

        $this->logger->debug('LLM request', [
            'provider' => $this->getProviderCode(),
            'model' => $model,
            'messages_count' => count($request->messages),
            'has_tools' => !empty($request->tools),
        ]);

        try {
            $body = $this->buildRequestBody($request);

            $response = $this->httpClient->post($this->getEndpoint(), [
                'headers' => $this->getHeaders(),
                'json' => $body,
            ]);

            $rawResponse = $response->getBody()->getContents();
            $responseBody = json_decode($rawResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw LLMException::invalidResponse(
                    $this->getProviderCode(),
                    'Invalid JSON response: ' . json_last_error_msg()
                );
            }

            $result = $this->parseResponse($responseBody);
            $this->writeRequestLog($request, $body, $responseBody, $result, $startTime);

            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info('LLM response', [
                'provider' => $this->getProviderCode(),
                'model' => $model,
                'elapsed_ms' => $elapsed,
                'input_tokens' => $result->usage->inputTokens,
                'output_tokens' => $result->usage->outputTokens,
                'finish_reason' => $result->finishReason,
            ]);

            return $result;
        } catch (RequestException $e) {
            $this->handleRequestException($e);
        } catch (ConnectException $e) {
            throw LLMException::connectionFailed($this->getProviderCode(), $e);
        }
    }

    public function ping(): array
    {
        $a = random_int(0, 9);
        $b = random_int(0, 9);
        $ops = ['+', '-', '*'];
        $op = $ops[array_rand($ops)];
        $question = "{$a} {$op} {$b} = ?";

        try {
            $start = microtime(true);
            $response = $this->httpClient->post($this->getEndpoint(), [
                'headers' => $this->getHeaders(),
                'json' => $this->buildPingBody($question),
            ]);

            $latency = round((microtime(true) - $start) * 1000);
            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'ok' => true,
                'latency_ms' => $latency,
                'model' => $data['model'] ?? $this->getDefaultModel(),
                'question' => $question,
                'response' => $this->extractPingResponse($data),
                'input_tokens' => $data['usage']['input_tokens'] ?? $data['usage']['prompt_tokens'] ?? 0,
                'output_tokens' => $data['usage']['output_tokens'] ?? $data['usage']['completion_tokens'] ?? 0,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    abstract protected function buildPingBody(string $question): array;

    abstract protected function extractPingResponse(array $data): string;

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    protected function createHttpClient(): Client
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));

        $timeout = $this->config['timeout_seconds'] ?? self::DEFAULT_TIMEOUT;

        return new Client([
            'timeout' => $timeout,
            'verify' => true,
            'handler' => $stack,
            'decode_content' => true,
        ]);
    }

    protected function retryDecider(): callable
    {
        return function (
            int $retries,
            Request $request,
            ?Response $response = null,
            ?\Throwable $exception = null
        ): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }

            if ($exception instanceof ConnectException) {
                $this->logger->warning('LLM connection failed, retrying', [
                    'provider' => $this->getProviderCode(),
                    'retry' => $retries + 1,
                ]);
                return true;
            }

            if ($response && $response->getStatusCode() >= 500) {
                $this->logger->warning('LLM server error, retrying', [
                    'provider' => $this->getProviderCode(),
                    'status' => $response->getStatusCode(),
                    'retry' => $retries + 1,
                ]);
                return true;
            }

            if ($response && $response->getStatusCode() === 429) {
                $this->logger->warning('LLM rate limited, retrying', [
                    'provider' => $this->getProviderCode(),
                    'retry' => $retries + 1,
                ]);
                return true;
            }

            return false;
        };
    }

    protected function retryDelay(): callable
    {
        return fn(int $retries): int => self::RETRY_DELAY_MS * (2 ** $retries);
    }

    protected function handleRequestException(RequestException $e): never
    {
        $response = $e->getResponse();
        $statusCode = $response?->getStatusCode() ?? 0;
        $headers = $response?->getHeaders() ?? [];

        $body = '';
        if ($response !== null) {
            try {
                $body = $response->getBody()->getContents();
            } catch (\Throwable) {
            }
        }

        $errorData = [];
        if (!empty($body)) {
            try {
                $errorData = json_decode($body, true, 512, JSON_THROW_ON_ERROR) ?? [];
            } catch (\JsonException) {
                $errorData = ['raw_body' => $body];
            }
        }

        $this->logger->error('LLM request failed', [
            'provider' => $this->getProviderCode(),
            'status' => $statusCode,
            'error' => $errorData,
        ]);

        match ($statusCode) {
            401 => throw AuthException::invalidApiKey($this->getProviderCode()),
            402 => throw AuthException::quotaExceeded($this->getProviderCode()),
            403 => throw AuthException::forbidden($this->getProviderCode(), $this->extractErrorMessage($errorData)),
            429 => throw RateLimitException::fromHeaders($this->getProviderCode(), $headers),
            400, 404, 422 => throw LLMException::fromApiResponse($this->getProviderCode(), $statusCode, $errorData, $e),
            500, 502, 503, 529 => throw $this->createServerError($statusCode, $errorData, $e),
            default => throw LLMException::fromApiResponse($this->getProviderCode(), $statusCode, $errorData, $e),
        };
    }

    private function extractErrorMessage(array $errorData): string
    {
        $message = $errorData['error']['message'] ?? $errorData['message'] ?? '';

        if (is_array($message)) {
            return implode("\n", $message);
        }

        return (string) $message;
    }

    private function createServerError(int $statusCode, array $errorData, \Throwable $previous): LLMException
    {
        $messages = [
            500 => 'Internal server error',
            502 => 'Bad gateway',
            503 => 'Service temporarily unavailable',
            529 => 'Service overloaded',
        ];

        $baseMessage = $messages[$statusCode] ?? 'Server error';
        $apiMessage = $this->extractErrorMessage($errorData);

        $message = $apiMessage ? "{$baseMessage}: {$apiMessage}" : $baseMessage;

        return new LLMException(
            $message,
            $this->getProviderCode(),
            $statusCode,
            $errorData,
            'server_error',
            null,
            $previous
        );
    }

    protected function writeRequestLog(
        ChatRequest $request,
        array $body,
        ?array $responseBody,
        ?ChatResponse $result,
        float $startTime
    ): void {
        $dir = '/var/www/app/runtime/logs/llm-requests';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $id = substr(md5(uniqid('', true)), 0, 8);
        $timestamp = date('Y-m-d_His');
        $filename = "{$dir}/{$timestamp}_{$this->getProviderCode()}_{$id}.json";

        $data = [
            'timestamp' => date('c'),
            'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'request' => $body,
            'response' => $responseBody,
        ];

        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function getDefaultModel(): string
    {
        return $this->config['model_name'] ?? '';
    }

    public function supportsTools(): bool
    {
        return (bool)($this->config['supports_functions'] ?? false);
    }

    protected function getMaxTokens(ChatRequest $request): int
    {
        return $request->maxTokens ?? (int)($this->config['max_tokens'] ?? 4096);
    }

    protected function getTemperature(ChatRequest $request): float
    {
        return $request->temperature ?? (float)($this->config['default_temperature'] ?? 0.7);
    }
}
