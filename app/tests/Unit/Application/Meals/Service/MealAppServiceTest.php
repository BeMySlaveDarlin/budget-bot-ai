<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Meals\Service;

use App\Application\Meals\Repository\MealFactConflictRepository;
use App\Application\Meals\Service\MealAppService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MealAppServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface $conflictRepository;
    private MealAppService $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conflictRepository = Mockery::mock(MealFactConflictRepository::class);
        $this->sut = new MealAppService($this->conflictRepository, new NullLogger());
    }

    public static function validActionsProvider(): array
    {
        return [
            'accept_new' => ['accept_new', true],
            'reject' => ['reject', false],
            'keep_both' => ['keep_both', true],
        ];
    }

    #[DataProvider('validActionsProvider')]
    public function testResolveConflictDelegatesValidActionAndReturnsRepoResult(string $action, bool $repoResult): void
    {
        $this->conflictRepository->shouldReceive('resolve')
            ->once()
            ->with(55, 10, $action)
            ->andReturn($repoResult);

        $this->assertSame($repoResult, $this->sut->resolveConflict(10, 55, $action));
    }

    public function testResolveConflictReturnsFalseForInvalidAction(): void
    {
        $this->conflictRepository->shouldNotReceive('resolve');

        $this->assertFalse($this->sut->resolveConflict(10, 55, 'foo'));
    }
}
