<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Service\Attribute\AttributeScanner;
use App\Service\Attribute\Command;
use DI\Attribute\Injectable;

#[Injectable]
class CommandCollector
{
    private static array $commandCache = [];

    public function __construct(
        private AttributeScanner $scanner,
    ) {
    }

    public function collectFromDirectories(array $directories): array
    {
        $cacheKey = md5(serialize($directories));

        if (isset(self::$commandCache[$cacheKey])) {
            return self::$commandCache[$cacheKey];
        }

        $commands = $this->scanner->scan(
            $directories,
            Command::class,
            function (string $className, object $attribute): array {
                return [
                    'key' => $attribute->name,
                    'value' => [
                        'class' => $className,
                        'name' => $attribute->name,
                        'description' => $attribute->description,
                    ],
                ];
            }
        );

        self::$commandCache[$cacheKey] = $commands;

        return $commands;
    }
}
