<?php

declare(strict_types=1);

namespace App\Service\Database\Dto;

use ReflectionClass;
use ReflectionProperty;

abstract class AbstractDto implements DtoInterface
{
    public function toArray(): array
    {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $data = [];
        foreach ($properties as $property) {
            $name = $property->getName();
            $data[$name] = $this->$name;
        }

        return $data;
    }

    protected static function mapData(array $data, array $mapping = []): array
    {
        if (empty($mapping)) {
            return $data;
        }

        $mapped = [];
        foreach ($mapping as $dtoProperty => $dbField) {
            if (array_key_exists($dbField, $data)) {
                $mapped[$dtoProperty] = $data[$dbField];
            }
        }

        return $mapped;
    }

    protected static function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            'array' => is_string($value) ? json_decode($value, true) : (array) $value,
            default => $value,
        };
    }
}
