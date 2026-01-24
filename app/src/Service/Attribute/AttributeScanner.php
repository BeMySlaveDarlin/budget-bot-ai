<?php

declare(strict_types=1);

namespace App\Service\Attribute;

use App\Service\Cache\CacheInterface;
use DI\Attribute\Injectable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;

#[Injectable]
class AttributeScanner
{
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    public function scan(array $directories, string $attributeClass, ?callable $processor = null): array
    {
        $cacheKey = 'attr_scan:' . md5(serialize($directories) . $attributeClass);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $results = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $this->extractClassNameFromFile($file->getPathname());
                if (!$className) {
                    continue;
                }

                $attributeInstance = $this->getAttributeInstance($className, $attributeClass);
                if (!$attributeInstance) {
                    continue;
                }

                if ($processor) {
                    $result = $processor($className, $attributeInstance);
                    if ($result !== null) {
                        if (is_array($result) && isset($result['key'])) {
                            $results[$result['key']] = $result['value'];
                        } else {
                            $results[] = $result;
                        }
                    }
                } else {
                    $results[] = $className;
                }
            }
        }

        $this->cache->set($cacheKey, $results, 3600);

        return $results;
    }

    public function scanRouteClasses(array $directories): array
    {
        return $this->scan($directories, Route::class);
    }

    public function scanSwooleEventHandlers(array $directories): array
    {
        return $this->scan(
            $directories,
            SwooleEventHandler::class,
            function (string $className, object $attribute): array {
                return [
                    'key' => $attribute->event,
                    'value' => $className,
                ];
            }
        );
    }

    private function extractClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if (!$content) {
            return null;
        }

        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch) &&
            preg_match('/class\s+(\w+)/', $content, $classMatch)
        ) {
            $namespace = trim($namespaceMatch[1]);
            $className = trim($classMatch[1]);

            return $namespace . '\\' . $className;
        }

        return null;
    }

    private function getAttributeInstance(string $className, string $attributeClass): ?object
    {
        try {
            $reflection = new ReflectionClass($className);
            $attributes = $reflection->getAttributes($attributeClass);

            if (empty($attributes)) {
                if ($attributeClass === Route::class && array_filter($reflection->getMethods(), static fn($method) => $method->getAttributes($attributeClass))) {
                    return new \stdClass();
                }

                return null;
            }

            return $attributes[0]->newInstance();
        } catch (ReflectionException) {
            return null;
        }
    }
}
