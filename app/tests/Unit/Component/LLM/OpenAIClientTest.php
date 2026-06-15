<?php

declare(strict_types=1);

namespace Tests\Unit\Component\LLM;

use App\Component\LLM\Client\OpenAIClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OpenAIClientTest extends TestCase
{
    private function usesCompletionTokens(string $model): bool
    {
        $client = new OpenAIClient([], 'test-key');

        return new ReflectionClass($client)
            ->getMethod('usesCompletionTokens')
            ->invoke($client, $model);
    }

    public static function modelsProvider(): array
    {
        return [
            'gpt-5.x' => ['gpt-5.1', true],
            'prefixed gpt-5 nano' => ['openai/gpt-5.4-nano', true],
            'o1 family' => ['o1-mini', true],
            'o3 family' => ['o3', true],
            'gpt-4o' => ['gpt-4o', false],
            'prefixed gpt-4o-mini' => ['openai/gpt-4o-mini', false],
        ];
    }

    #[DataProvider('modelsProvider')]
    public function testUsesCompletionTokens(string $model, bool $expected): void
    {
        $this->assertSame($expected, $this->usesCompletionTokens($model));
    }
}
