<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Threat;

use GES\Botlock\Challenge\PuzzleBudget;
use GES\Botlock\Challenge\PuzzleBudgetResult;
use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Tests\Support\InMemoryThreatStateStore;
use GES\Botlock\Threat\LazyThreatStateStore;
use PHPUnit\Framework\TestCase;

final class LazyThreatStateStoreTest extends TestCase
{
    private string $errorLog;
    private string|false $previousErrorLog;
    private int $opened = 0;

    protected function setUp(): void
    {
        $this->errorLog = \tempnam(\sys_get_temp_dir(), 'botlock-errlog');
        $this->previousErrorLog = \ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', (string) $this->previousErrorLog);
        @\unlink($this->errorLog);
    }

    public function testOpensTheStoreOnFirstUseOnly(): void
    {
        $inner = new InMemoryThreatStateStore();
        $store = new LazyThreatStateStore(function () use ($inner): InMemoryThreatStateStore {
            $this->opened++;

            return $inner;
        });

        self::assertSame(0, $this->opened, 'nothing is opened at construction');

        self::assertTrue($store->recordIndividual('fp', 100, 0));
        self::assertSame(1, $store->countIndividual('fp', 0));
        self::assertSame([100], $store->individualTimestamps('fp', 0));
        self::assertSame(['n' => 1], $store->updateGlobal(static fn(array $state): array => ['n' => 1]));
        self::assertSame(['n' => 1], $store->readGlobal());
        self::assertSame(0, $store->collectGarbage(0));
        self::assertSame(1, $this->opened);
    }

    public function testAStoreThatCannotBeOpenedAnswersAsAFailedOne(): void
    {
        $store = new LazyThreatStateStore(function (): never {
            $this->opened++;

            throw new \RuntimeException("State directory '/state/puzzles' is not writable.");
        });

        self::assertNull($store->updateGlobal(static fn(array $state): array => $state));
        self::assertNull($store->readGlobal());
        self::assertFalse($store->recordIndividual('fp', 100, 0));
        self::assertNull($store->countIndividual('fp', 0));
        self::assertNull($store->individualTimestamps('fp', 0));
        self::assertSame(0, $store->collectGarbage(0));
        self::assertSame(1, $this->opened, 'not retried within the request');
        self::assertStringContainsString("Botlock: state store unavailable: State directory '/state/puzzles' is not writable.", (string) \file_get_contents($this->errorLog));
    }

    public function testThePuzzleBudgetRefusesWhenItsStoreCannotBeOpened(): void
    {
        $budget = new PuzzleBudget(
            new RateLimitConfig(gcProbability: 0),
            new LazyThreatStateStore(static fn(): never => throw new \RuntimeException('no store')),
            'inst',
        );

        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve('203.0.113.10', 'fp'));
    }
}
