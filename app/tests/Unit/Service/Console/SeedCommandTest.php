<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Console;

use App\Service\Console\Handler\SeedCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeedCommandTest extends TestCase
{
    private function orderSeeders(array $seeders): array
    {
        $reflection = new ReflectionClass(SeedCommand::class);
        $command = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod('orderSeeders')->invoke($command, $seeders);
    }

    public function testOrderSeedersPutsLlmProviderBeforeBotConfig(): void
    {
        $ordered = $this->orderSeeders([
            'BotConfigSeeder' => 'Database\Seeders\BotConfigSeeder',
            'LlmProviderSeeder' => 'Database\Seeders\LlmProviderSeeder',
        ]);

        $this->assertSame(['LlmProviderSeeder', 'BotConfigSeeder'], array_keys($ordered));
    }

    public function testOrderSeedersPutsPrioritySeedersFirstAndRestAlphabetically(): void
    {
        $ordered = $this->orderSeeders([
            'ZebraSeeder' => 'Database\Seeders\ZebraSeeder',
            'BotConfigSeeder' => 'Database\Seeders\BotConfigSeeder',
            'AlphaSeeder' => 'Database\Seeders\AlphaSeeder',
            'LlmProviderSeeder' => 'Database\Seeders\LlmProviderSeeder',
            'ExchangeRatesSeeder' => 'Database\Seeders\ExchangeRatesSeeder',
        ]);

        $this->assertSame(
            ['LlmProviderSeeder', 'BotConfigSeeder', 'AlphaSeeder', 'ExchangeRatesSeeder', 'ZebraSeeder'],
            array_keys($ordered)
        );
    }

    public function testOrderSeedersWorksWithoutPrioritySeeders(): void
    {
        $ordered = $this->orderSeeders([
            'BetaSeeder' => 'Database\Seeders\BetaSeeder',
            'AlphaSeeder' => 'Database\Seeders\AlphaSeeder',
        ]);

        $this->assertSame(['AlphaSeeder', 'BetaSeeder'], array_keys($ordered));
    }
}
