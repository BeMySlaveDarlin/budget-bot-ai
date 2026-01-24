<?php

declare(strict_types=1);

namespace App\Service\Swoole\Task\Handler;

use App\Service\Swoole\Task\Contract\TaskInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

abstract class AbstractTask implements TaskInterface
{
    protected array $payload = [];
    protected ?ContainerInterface $container = null;
    protected int $maxRetries = 3;
    protected int $retryDelay = 1000;
    protected array $next = [];

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
    }

    public static function fromPayload(array $payload): static
    {
        return new static($payload);
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    protected function getService(string $class): mixed
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container not set');
        }

        return $this->container->get($class);
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->getService(LoggerInterface::class);
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    public function getType(): string
    {
        return static::class;
    }

    public function getNext(): array
    {
        return $this->next;
    }

    abstract public function handle(): mixed;
}
