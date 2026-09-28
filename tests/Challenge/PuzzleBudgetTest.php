<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\PuzzleBudget;
use GES\Botlock\Challenge\PuzzleBudgetResult;
use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Tests\Support\InMemoryThreatStateStore;
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

    public function testRefusedClientsRetryAfterTheWindow(): void
    {
        self::assertSame(600, $this->budget()->retryAfter(PuzzleBudgetResult::ClientExhausted));
        self::assertSame(0, $this->budget()->retryAfter(PuzzleBudgetResult::Granted));
        self::assertSame(1, $this->budget(window: 0)->retryAfter(PuzzleBudgetResult::ClientExhausted), 'at least a second');
    }

    public function testSweepsItsOwnWindow(): void
    {
        $this->budget(gcProbability: 1)->reserve(self::IP, 'fp');

        self::assertSame([['windowStart' => $this->now - 600, 'maxEntries' => 500]], $this->store->gcCalls);
    }

    private function budget(int $limit = 10, int $window = 600, int $gcProbability = 0, string $instanceId = 'inst'): PuzzleBudget
    {
        return new PuzzleBudget(
            new RateLimitConfig(gcProbability: $gcProbability, sliderIpLimit: $limit, sliderIpWindowSec: $window),
            $this->store,
            $instanceId,
            fn(): int => $this->now,
        );
    }
}
