<?php

declare(strict_types=1);

namespace App\Service\Logging;

use App\Service\Config\Config;
use DI\Attribute\Injectable;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Psr\Log\LoggerInterface;

#[Injectable]
class LoggerFactory
{
    private array $loggers = [];

    public function __construct(
        private Config $config
    ) {
    }

    public function create(?string $channel = null): LoggerInterface
    {
        $channel = $channel ?? $this->config->get('logging.default_channel', 'app');
        if (isset($this->loggers[$channel])) {
            return $this->loggers[$channel];
        }

        $logger = new Logger($channel);
        $channelConfig = $this->config->get("logging.channels.{$channel}", []);
        foreach ($channelConfig['handlers'] ?? [] as $handlerConfig) {
            $handler = $this->createHandler($handlerConfig);
            if ($handler) {
                $logger->pushHandler($handler);
            }
        }

        $this->addProcessors($logger);

        $this->loggers[$channel] = $logger;

        return $logger;
    }

    private function createHandler(array $config): ?HandlerInterface
    {
        $class = $config['class'] ?? null;
        if (!$class) {
            return null;
        }

        $level = Level::fromName($config['level'] ?? 'info');
        $handler = match ($class) {
            RotatingFileHandler::class => new RotatingFileHandler(
                $config['path'],
                $config['max_files'] ?? 14,
                $level
            ),
            StreamHandler::class => new StreamHandler(
                $config['stream'],
                $level
            ),
            default => null,
        };

        if ($handler && isset($config['formatter'])) {
            $formatter = $this->createFormatter($config['formatter']);
            if ($formatter) {
                $handler->setFormatter($formatter);
            }
        }

        return $handler;
    }

    private function createFormatter(string $type): ?FormatterInterface
    {
        $formatters = $this->config->get('logging.formatters', []);
        $formatterConfig = $formatters[$type] ?? [];

        return match ($type) {
            'json' => new JsonFormatter(
                $formatterConfig['batch_mode'] ?? JsonFormatter::BATCH_MODE_NEWLINES,
                $formatterConfig['append_newline'] ?? true,
                false,
                $formatterConfig['include_stacktraces'] ?? true
            ),
            'line' => new LineFormatter(
                $formatterConfig['format'] ?? null,
                $formatterConfig['date_format'] ?? null,
                $formatterConfig['allow_inline_line_breaks'] ?? false
            ),
            default => null,
        };
    }

    private function addProcessors(Logger $logger): void
    {
        $processors = $this->config->get('logging.processors', []);
        foreach ($processors as $processorName => $processorConfig) {
            $processor = match ($processorName) {
                'memory' => new MemoryUsageProcessor(),
                'process_id' => new ProcessIdProcessor(),
                'introspection' => new IntrospectionProcessor(
                    Level::fromName($processorConfig['level'] ?? 'debug'),
                    $processorConfig['skip_classes_partial'] ?? ['Monolog\\'],
                    $processorConfig['skip_stack_frames'] ?? 2
                ),
                default => null,
            };

            if ($processor) {
                $logger->pushProcessor($processor);
            }
        }
    }
}
