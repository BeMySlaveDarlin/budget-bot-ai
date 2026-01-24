<?php

declare(strict_types=1);

namespace App\Service\Http\Context\Response;

use Swoole\Http\Response as SwooleResponse;

class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private string $body = '';

    public function __construct(
        private SwooleResponse $swooleResponse
    ) {}

    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function withStatus(int $code): self
    {
        return $this->status($code);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        return $this->header($name, $value);
    }

    public function withBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function json(array $data, int $status = 200): self
    {
        $this->statusCode = $status;
        $this->header('Content-Type', 'application/json');
        $this->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $this;
    }

    public function withJson(mixed $data, int $flags = 0): self
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | $flags);

        return $this
            ->withHeader('Content-Type', 'application/json')
            ->withBody($json);
    }

    public function text(string $text): self
    {
        $this->header('Content-Type', 'text/plain; charset=utf-8');
        $this->body = $text;
        return $this;
    }

    public function send(): void
    {
        $this->swooleResponse->status($this->statusCode);

        foreach ($this->headers as $name => $value) {
            $this->swooleResponse->header($name, $value);
        }

        $this->swooleResponse->end($this->body);
    }
}
