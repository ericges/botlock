<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\PuzzleBudget;
use GES\Botlock\Challenge\PuzzleBudgetResult;
use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Tests\Support\InMemoryThreatStateStore;
use GES\Botlock\Threat\ThreatStateStore;
use PHPUnit\Framework\TestCase;

final class PuzzleBudgetTest extends TestCase
{
    private const IP = '203.0.113.10';

    private InMemoryThreatStateStore $store;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->store = new InMemoryThreatStateStore();
    }

    public function testAClientGetsItsLimitPerWindow(): void
    {
        $budget = $this->budget(limit: 3);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp'));
        }
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve(self::IP, 'fp'));

        $this->now += 300;
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve(self::IP, 'fp'), 'still inside the window');

        $this->now += 301;
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp'), 'refusals did not extend the wait');
    }

    public function testTheIpCountsNotTheFingerprint(): void
    {
        $budget = $this->budget(limit: 1);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp-a'));
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve(self::IP, 'fp-b'), 'new headers, same IP');
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.11', 'fp-a'), 'another IP');
    }

    public function testWithoutAnIpTheFingerprintCounts(): void
    {
        $budget = $this->budget(limit: 1);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(null, 'fp-a'));
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve('', 'fp-a'), 'an empty IP is no IP');
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(null, 'fp-b'));
    }

    public function testIpv6ClientsAreCountedByTheirSlash64Network(): void
    {
        $budget = $this->budget(limit: 1);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('2001:db8:1:2::1', 'fp'));
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve('2001:db8:1:2:ffff::9', 'fp'), 'same /64');
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('2001:db8:1:3::1', 'fp'), 'another /64');
    }

    public function testIpv4MappedIpv6SharesTheIpv4Budget(): void
    {
        $budget = $this->budget(limit: 1);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp'));
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve('::ffff:' . self::IP, 'fp'));
    }

    public function testZeroLimitDisablesTheClientBudget(): void
    {
        $budget = $this->budget(limit: 0);

        for ($i = 0; $i < 50; $i++) {
            self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp'));
        }
        self::assertSame([], $this->store->individual, 'nothing is counted');
    }

    public function testAnUnavailableStoreRefuses(): void
    {
        $this->store = new InMemoryThreatStateStore(failIndividualRead: true);
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $this->budget()->reserve(self::IP, 'fp'), 'unreadable');

        $this->store = new InMemoryThreatStateStore(failIndividualWrite: true);
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $this->budget()->reserve(self::IP, 'fp'), 'unwritable');
    }

    public function testNoRawAddressIsStored(): void
    {
        $this->budget()->reserve(self::IP, 'fp');
        $this->budget(instanceId: 'other')->reserve(self::IP, 'fp');

        $keys = \array_keys($this->store->individual);
        self::assertCount(2, $keys, 'each instance counts on its own');
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        }
    }

    public function testRefusedClientsRetryWhenTheirOldestRenderLeavesTheWindow(): void
    {
        $budget = $this->budget(limit: 3);
        foreach ([0, 120, 300] as $offset) {
            $this->now = 1_800_000_000 + $offset;
            self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp'));
        }

        $this->now = 1_800_000_000 + 540;
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve(self::IP, 'fp'));
        self::assertSame(61, $budget->retryAfter(PuzzleBudgetResult::ClientExhausted, self::IP, 'fp'), 'the render at 0 counts until 600');

        $this->now += 61;
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve(self::IP, 'fp'), 'and not a second longer');
    }

    public function testRetryTimeDoesNotDependOnTheStoredOrder(): void
    {
        $budget = $this->budget(limit: 3);
        foreach ([0, 120, 300] as $offset) {
            $this->now = 1_800_000_000 + $offset;
            $budget->reserve(self::IP, 'fp');
        }

        // Parallel writers may append out of order.
        $key = \array_key_first($this->store->individual);
        $this->store->individual[$key] = [300 + 1_800_000_000, 1_800_000_000, 120 + 1_800_000_000];

        $this->now = 1_800_000_000 + 540;
        self::assertSame(61, $budget->retryAfter(PuzzleBudgetResult::ClientExhausted, self::IP, 'fp'));
    }

    public function testRetryFallsBackToTheWindow(): void
    {
        self::assertSame(600, $this->budget()->retryAfter(PuzzleBudgetResult::ClientExhausted, self::IP, 'fp'), 'nothing counted, a failed write refused');
        self::assertSame(0, $this->budget()->retryAfter(PuzzleBudgetResult::Granted, self::IP, 'fp'));

        $this->store = new InMemoryThreatStateStore(failIndividualRead: true);
        self::assertSame(600, $this->budget()->retryAfter(PuzzleBudgetResult::ClientExhausted, self::IP, 'fp'), 'unreadable');
        self::assertSame(1, $this->budget(window: 0)->retryAfter(PuzzleBudgetResult::ClientExhausted, self::IP, 'fp'), 'at least a second');
    }

    public function testSweepsItsOwnWindow(): void
    {
        $this->budget(gcProbability: 1)->reserve(self::IP, 'fp');

        self::assertSame([['windowStart' => $this->now - 600, 'maxEntries' => 500]], $this->store->gcCalls);
    }

    // 1_800_000_000 is the first second of a minute.

    public function testTheGlobalLimitCapsAllClientsPerMinute(): void
    {
        $budget = $this->budget(globalLimit: 2);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.1', 'a'));
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.2', 'b'));
        self::assertSame(PuzzleBudgetResult::GlobalExhausted, $budget->reserve('203.0.113.3', 'c'));

        $this->now += 59;
        self::assertSame(PuzzleBudgetResult::GlobalExhausted, $budget->reserve('203.0.113.3', 'c'), 'the last second of the minute');

        $this->now += 1;
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.3', 'c'), 'a new minute');
    }

    public function testAClientOverItsBudgetTakesNoGlobalSlot(): void
    {
        $budget = $this->budget(limit: 1, globalLimit: 2);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.1', 'a'));
        self::assertSame(PuzzleBudgetResult::ClientExhausted, $budget->reserve('203.0.113.1', 'a'));
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.2', 'b'), 'the refused client took no slot');
    }

    public function testARefusedGlobalSlotCostsTheClientNothing(): void
    {
        $budget = $this->budget(limit: 1, globalLimit: 1);

        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.1', 'a'));
        self::assertSame(PuzzleBudgetResult::GlobalExhausted, $budget->reserve('203.0.113.2', 'b'));

        $this->now += 60;
        self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve('203.0.113.2', 'b'), 'its own budget is untouched');
    }

    public function testZeroGlobalLimitDisablesTheCap(): void
    {
        $budget = $this->budget(globalLimit: 0);

        for ($i = 1; $i <= 50; $i++) {
            self::assertSame(PuzzleBudgetResult::Granted, $budget->reserve("203.0.113.$i", 'fp'));
        }
        self::assertSame([], $this->store->global, 'nothing is counted');
    }

    public function testConcurrentRendersFromOneClientStayWithinTheLimit(): void
    {
        // A minimal store double, delegating to a real InMemoryThreatStateStore,
        // that turns its first countIndividual() call into an interleaving
        // point: before returning that (soon-to-be stale) count, it lets a
        // second, full reserve() call for the same client run to completion,
        // recording a render in between this request's first count and its
        // own record — the race the fix closes.
        $store = new class(new InMemoryThreatStateStore()) implements ThreatStateStore {
            public ?\Closure $onFirstCount = null;
            private bool $fired = false;

            public function __construct(private InMemoryThreatStateStore $inner) {}

            public function updateGlobal(callable $reducer): ?array
            {
                return $this->inner->updateGlobal($reducer);
            }

            public function readGlobal(): ?array
            {
                return $this->inner->readGlobal();
            }

            public function recordIndividual(string $fingerprint, int $now, int $windowStart): bool
            {
                return $this->inner->recordIndividual($fingerprint, $now, $windowStart);
            }

            public function collectGarbage(int $windowStart, int $maxEntries = 500): int
            {
                return $this->inner->collectGarbage($windowStart, $maxEntries);
            }

            public function individualTimestamps(string $fingerprint, int $windowStart): ?array
            {
                return $this->inner->individualTimestamps($fingerprint, $windowStart);
            }

            public function countIndividual(string $fingerprint, int $windowStart): ?int
            {
                $count = $this->inner->countIndividual($fingerprint, $windowStart);

                if (!$this->fired && $this->onFirstCount !== null) {
                    $this->fired = true;
                    ($this->onFirstCount)();
                }

                return $count;
            }
        };

        $budget = new PuzzleBudget(
            new RateLimitConfig(sliderIpLimit: 1, sliderIpWindowSec: 600),
            $store,
            'inst',
            fn(): int => $this->now,
        );

        $second = null;
        $store->onFirstCount = function () use ($budget, &$second): void {
            $second = $budget->reserve(self::IP, 'fp');
        };

        $first = $budget->reserve(self::IP, 'fp');

        $granted = \array_filter([$first, $second], static fn(PuzzleBudgetResult $result): bool => $result === PuzzleBudgetResult::Granted);
        self::assertCount(1, $granted, 'only one of the two racing reservations may be Granted');
    }

    public function testAFailedGlobalUpdateRefuses(): void
    {
        $this->store = new InMemoryThreatStateStore(failGlobalWrite: true);

        self::assertSame(PuzzleBudgetResult::GlobalExhausted, $this->budget()->reserve(self::IP, 'fp'));
        self::assertSame([], $this->store->individual, 'the client keeps its render');
    }

    public function testBusyClientsRetryAtTheNextMinute(): void
    {
        self::assertSame(60, $this->budget()->retryAfter(PuzzleBudgetResult::GlobalExhausted, self::IP, 'fp'));

        $this->now += 45;
        self::assertSame(15, $this->budget()->retryAfter(PuzzleBudgetResult::GlobalExhausted, self::IP, 'fp'));
    }

    private function budget(int $limit = 10, int $window = 600, int $gcProbability = 0, string $instanceId = 'inst', int $globalLimit = 300): PuzzleBudget
    {
        return new PuzzleBudget(
            new RateLimitConfig(gcProbability: $gcProbability, sliderIpLimit: $limit, sliderIpWindowSec: $window, sliderGlobalLimit: $globalLimit),
            $this->store,
            $instanceId,
            fn(): int => $this->now,
        );
    }
}
