<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Manager;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Manager\ThreatAwarenessManager;
use GES\Botlock\Tests\Support\InMemoryThreatStateStore;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ThreatAwarenessManagerTest extends TestCase
{
    private const FP = 'abcdef0123456789';

    public function testRecordRequestCountsGloballyAndIndividually(): void
    {
        $store = new InMemoryThreatStateStore();
        $manager = new ThreatAwarenessManager(new RateLimitConfig(gcProbability: 0), $store);

        $request = Requests::make();
        $request->context->fingerprint = self::FP;

        $manager->recordRequest($request);
        $manager->recordRequest($request);

        self::assertSame(2, \array_sum($store->global['traffic_buckets']));
        self::assertCount(2, $store->individual[self::FP]);
        self::assertSame(2, $manager->getIndividualRate(self::FP));
        self::assertSame([], $store->gcCalls);
    }

    public function testRecordingPrunesGlobalBucketsOlderThanFiveMinutes(): void
    {
        $store = new InMemoryThreatStateStore();
        $store->global = ['current_level' => 0, 'level_last_changed' => 0, 'traffic_buckets' => ['1' => 99]];
        $manager = new ThreatAwarenessManager(new RateLimitConfig(gcProbability: 0), $store);

        $request = Requests::make();
        $request->context->fingerprint = self::FP;
        $manager->recordRequest($request);

        self::assertArrayNotHasKey('1', $store->global['traffic_buckets']);
        self::assertSame(1, \array_sum($store->global['traffic_buckets']));
    }

    public function testIndividualLevelFollowsThresholds(): void
    {
        $store = new InMemoryThreatStateStore();
        $config = new RateLimitConfig(level1ThresholdIndividual: 2, level2ThresholdIndividual: 3, level3ThresholdIndividual: 4, gcProbability: 0);

        $request = Requests::make();
        $request->context->fingerprint = self::FP;

        // thresholds 2/3/4 requests per window
        foreach ([0 => 0, 1 => 0, 2 => 1, 3 => 2, 4 => 3, 9 => 3] as $hits => $expectedLevel) {
            $manager = new ThreatAwarenessManager($config, $store);
            $store->individual = [self::FP => \array_fill(0, $hits, \time())];

            self::assertSame($expectedLevel, $manager->getIndividualThreatLevel(self::FP), "after $hits hits");
        }
    }

    public function testIndividualRateIgnoresTimestampsOutsideWindow(): void
    {
        $store = new InMemoryThreatStateStore();
        $store->individual = [self::FP => [\time() - 120, \time() - 61, \time() - 30, \time()]];
        $manager = new ThreatAwarenessManager(new RateLimitConfig(individualRateWindowSec: 60), $store);

        self::assertSame(2, $manager->getIndividualRate(self::FP));
    }

    public function testGlobalLevelUsesRecencyWeightedScore(): void
    {
        $config = new RateLimitConfig(level1ThresholdGlobal: 120, level2ThresholdGlobal: 300, level3ThresholdGlobal: 600);
        $bucket = (string) \intdiv(\time(), 60);

        // A single, newest bucket weighs 1.05^8 ≈ 1.477.
        self::assertSame(0, $this->globalLevelFor($config, [$bucket => 81]));   // 119.7
        self::assertSame(1, $this->globalLevelFor($config, [$bucket => 82]));   // 121.1
        self::assertSame(2, $this->globalLevelFor($config, [$bucket => 204])); // 301.4
        self::assertSame(3, $this->globalLevelFor($config, [$bucket => 407])); // 601.3
    }

    public function testOlderBucketsWeighLess(): void
    {
        $config = new RateLimitConfig(level1ThresholdGlobal: 120);
        $now = (string) \intdiv(\time(), 60);
        $old = (string) (\intdiv(\time(), 60) - 4);

        self::assertSame(1, $this->globalLevelFor($config, [$now => 82]));
        self::assertSame(0, $this->globalLevelFor($config, [$old => 82, $now => 0]), 'the same count four minutes ago weighs ~0.85^8');
    }

    public function testRaisedGlobalLevelIsHeldDuringGracePeriod(): void
    {
        $config = new RateLimitConfig(levelDecayGracePeriod: 300);

        $held = new InMemoryThreatStateStore();
        $held->global = ['current_level' => 3, 'level_last_changed' => \time() - 10, 'traffic_buckets' => []];
        self::assertSame(3, (new ThreatAwarenessManager($config, $held))->getGlobalThreatLevel());

        $decayed = new InMemoryThreatStateStore();
        $decayed->global = ['current_level' => 3, 'level_last_changed' => \time() - 301, 'traffic_buckets' => []];
        self::assertSame(0, (new ThreatAwarenessManager($config, $decayed))->getGlobalThreatLevel());
    }

    public function testUnavailableStoreYieldsProtectiveLevelOne(): void
    {
        $store = new InMemoryThreatStateStore(failing: true);
        $manager = new ThreatAwarenessManager(new RateLimitConfig(), $store);

        $request = Requests::make();
        $request->context->fingerprint = self::FP;
        $manager->recordRequest($request);

        self::assertSame(1, $manager->getGlobalThreatLevel());
        self::assertNull($manager->getIndividualRate(self::FP));
        self::assertSame(1, $manager->getIndividualThreatLevel(self::FP));
    }

    public function testIndividualOnlyModeFailsClosedWhenStateIsUnreadable(): void
    {
        $store = new InMemoryThreatStateStore(failIndividualRead: true);
        $manager = new ThreatAwarenessManager(new RateLimitConfig(enableGlobalRateLimit: false, gcProbability: 0), $store);

        $request = Requests::make();
        $request->context->fingerprint = self::FP;
        $manager->recordRequest($request);

        self::assertCount(1, $store->individual[self::FP], 'the write itself succeeded');
        self::assertNull($manager->getIndividualRate(self::FP));
        self::assertSame(ThreatAwarenessManager::UNAVAILABLE_LEVEL, $manager->getIndividualThreatLevel(self::FP));
    }

    public function testFailedIndividualWriteYieldsProtectiveLevelForThisRequest(): void
    {
        $store = new InMemoryThreatStateStore(failIndividualWrite: true);
        $config = new RateLimitConfig(enableGlobalRateLimit: false, gcProbability: 0);

        $request = Requests::make();
        $request->context->fingerprint = self::FP;

        $recording = new ThreatAwarenessManager($config, $store);
        $recording->recordRequest($request);

        self::assertNull($recording->getIndividualRate(self::FP));
        self::assertSame(ThreatAwarenessManager::UNAVAILABLE_LEVEL, $recording->getIndividualThreatLevel(self::FP));

        // Reading alone still works, so the failed write is what triggered the protective level.
        $readOnly = new ThreatAwarenessManager($config, $store);
        self::assertSame(0, $readOnly->getIndividualRate(self::FP));
        self::assertSame(0, $readOnly->getIndividualThreatLevel(self::FP));
    }

    public function testRateLimitSwitchesAreRespected(): void
    {
        $store = new InMemoryThreatStateStore();
        $request = Requests::make();
        $request->context->fingerprint = self::FP;

        (new ThreatAwarenessManager(new RateLimitConfig(enableRateLimit: false), $store))->recordRequest($request);
        self::assertSame([], $store->global);
        self::assertSame([], $store->individual);

        (new ThreatAwarenessManager(new RateLimitConfig(enableGlobalRateLimit: false, gcProbability: 0), $store))->recordRequest($request);
        self::assertSame([], $store->global);
        self::assertCount(1, $store->individual[self::FP]);
    }

    public function testGarbageCollectionRunsWithConfiguredProbability(): void
    {
        $store = new InMemoryThreatStateStore();
        $request = Requests::make();
        $request->context->fingerprint = self::FP;

        (new ThreatAwarenessManager(new RateLimitConfig(gcProbability: 1, individualRateWindowSec: 60), $store))->recordRequest($request);

        self::assertCount(1, $store->gcCalls);
        self::assertEqualsWithDelta(\time() - 60, $store->gcCalls[0]['windowStart'], 2);
    }

    public function testEmptyFingerprintIsTreatedAsSuspicious(): void
    {
        $manager = new ThreatAwarenessManager(new RateLimitConfig(), new InMemoryThreatStateStore());

        self::assertSame(1, $manager->getIndividualThreatLevel(''));
        self::assertSame(0, $manager->getIndividualRate(''));
    }

    private function globalLevelFor(RateLimitConfig $config, array $buckets): int
    {
        $store = new InMemoryThreatStateStore();
        $store->global = ['current_level' => 0, 'level_last_changed' => 0, 'traffic_buckets' => $buckets];

        return (new ThreatAwarenessManager($config, $store))->getGlobalThreatLevel();
    }
}
