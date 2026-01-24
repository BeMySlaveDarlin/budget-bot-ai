<?php

declare(strict_types=1);

namespace App\Service\Http\Context;

use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;

class HttpContext
{
    private readonly string $requestId;
    private array $storage = [];

    public function __construct(
        private Request $request,
        private Response $response,
    ) {
        $this->requestId = uniqid('req_', true);
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function set(string $key, mixed $value): void
    {
        $this->storage[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->storage[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->storage);
    }

    public function remove(string $key): void
    {
        unset($this->storage[$key]);
    }
}
