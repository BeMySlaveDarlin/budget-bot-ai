<?php

declare(strict_types=1);

namespace App\Service\Http\Context\Request;

use Swoole\Http\Request as SwooleRequest;

class Request
{
    private array $parsedBody;

    public function __construct(
        private SwooleRequest $swooleRequest
    ) {
        $this->parsedBody = $this->parseBody();
    }

    public function getMethod(): string
    {
        return strtoupper($this->swooleRequest->server['request_method'] ?? 'GET');
    }

    public function getUri(): string
    {
        return $this->swooleRequest->server['request_uri'] ?? '/';
    }

    public function getPath(): string
    {
        $uri = $this->getUri();
        return parse_url($uri, PHP_URL_PATH) ?: '/';
    }

    public function getQueryParams(): array
    {
        return $this->swooleRequest->get ?? [];
    }

    public function getBody(): array
    {
        return $this->parsedBody;
    }

    public function getRawBody(): string
    {
        return $this->swooleRequest->rawContent() ?: '';
    }

    public function getHeader(string $name): ?string
    {
        $name = strtolower($name);
        return $this->swooleRequest->header[$name] ?? null;
    }

    public function getQueryParam(string $name, mixed $default = null): mixed
    {
        return $this->swooleRequest->get[$name] ?? $default;
    }

    private function parseBody(): array
    {
        $content = $this->swooleRequest->rawContent();
        if (empty($content)) {
            return [];
        }

        $contentType = $this->getHeader('content-type') ?? '';

        if (str_contains($contentType, 'application/json')) {
            return json_decode($content, true) ?? [];
        }

        return $this->swooleRequest->post ?? [];
    }
}
