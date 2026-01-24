<?php

declare(strict_types=1);

namespace App\Service\Config;

class Config
{
    private array $data = [];
    private array $cache = [];

    public function __construct(
        private string $configPath,
        private string $environment = 'dev'
    ) {}

    public function load(): void
    {
        $this->loadConfigFiles();
        $this->mergeEnvVariables();
    }

    private function loadConfigFiles(): void
    {
        $files = glob($this->configPath . '/*.php');

        foreach ($files as $file) {
            $filename = basename($file, '.php');
            if (str_contains($filename, '.')) {
                continue;
            }

            $this->data[$filename] = require $file;

            $envFile = $this->configPath . '/' . $filename . '.' . $this->environment . '.php';
            if (file_exists($envFile)) {
                $envConfig = require $envFile;
                $this->data[$filename] = $this->merge($this->data[$filename], $envConfig);
            }
        }
    }

    private function mergeEnvVariables(): void
    {
        $parsed = $this->parseEnvVariables($_ENV);
        foreach ($parsed as $section => $values) {
            if (isset($this->data[$section]) && is_array($this->data[$section])) {
                $this->data[$section] = $this->merge($this->data[$section], $values);
            } else {
                $this->data[$section] = $values;
            }
        }
    }

    private function parseEnvVariables(array $env): array
    {
        $result = [];
        foreach ($env as $key => $value) {
            $parts = explode('_', $key, 2);
            if (count($parts) === 2) {
                $section = strtolower($parts[0]);
                $name = strtolower($parts[1]);
                $result[$section][$name] = $this->cast($value);
            }
        }
        return $result;
    }

    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }

        $value = $this->data;
        foreach (explode('.', $path) as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return $this->cache[$path] = $default;
            }
        }

        return $this->cache[$path] = $value;
    }

    private function cast(mixed $value): mixed
    {
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if (is_numeric($value) && !str_contains((string)$value, '.')) {
            return (int)$value;
        }
        return $value;
    }

    public function validate(string $path, array $rules = ['required']): void
    {
        $value = $this->get($path);
        if (empty($value) && in_array('required', $rules, true)) {
            throw new ConfigException("Config key '{$path}' is required");
        }
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }
}
