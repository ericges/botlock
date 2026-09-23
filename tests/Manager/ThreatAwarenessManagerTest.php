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
        $config = new RateLimitConfig(level1ThresholdIndividual: 2, level2ThresholdIndividual: 3, level3ThresholdIndividual: 4, level4ThresholdIndividual: 6, gcProbability: 0);

        $request = Requests::make();
        $request->context->fingerprint = self::FP;

        // thresholds 2/3/4/6 requests per window
        foreach ([0 => 0, 1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 3, 6 => 4, 9 => 4] as $hits => $expectedLevel) {
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
        self::assertSame(3, $this->globalLevelFor($config, [$bucket => 100000]), 'the global level never reaches 4');
    }

    public function testOlderBucketsWeighLess(): void
    {
        $config = new RateLimitConfig(level1ThresholdGlobal: 120, level2ThresholdGlobal: 300);
        $now = (string) \intdiv(\time(), 60);
        $old = (string) (\intdiv(\time(), 60) - 4);

        self::assertSame(1, $this->globalLevelFor($config, [$now => 82]));
        // 300 * 0.85^8 ≈ 82 → level 0; weighting by array position would give 300 * 1.0 → level 2
        self::assertSame(0, $this->globalLevelFor($config, [$old => 300, $now => 0]), 'the same count four minutes ago weighs ~0.85^8');
    }

    public function testSparseBucketsAreWeightedByAgeNotPosition(): void
    {
        $config = new RateLimitConfig(level1ThresholdGlobal: 120);
        $minute = \intdiv(\time(), 60);
        $now = (string) $minute;
        $old = (string) ($minute - 4);

        // 120 * 0.85^8 + 1 * 1.05^8 ≈ 34.2 → level 0. A position-based weight would
        // give the gap-less pair 120 * 1.0 + 1.48 ≈ 121.5 → level 1.
        self::assertSame(0, $this->globalLevelFor($config, [$old => 120, $now => 1]));

        // The four-minute-old bucket alone crosses 120 at 441 requests (440 * 0.2725 ≈ 119.9).
        self::assertSame(0, $this->globalLevelFor($config, [$old => 440]));
        self::assertSame(1, $this->globalLevelFor($config, [$old => 441]));

        // The oldest retained bucket, five minutes back, weighs 0.8^8 ≈ 0.168: 715 → 119.96, 716 → 120.1.
        $oldest = (string) ($minute - 5);
        self::assertSame(0, $this->globalLevelFor($config, [$oldest => 715]));
        self::assertSame(1, $this->globalLevelFor($config, [$oldest => 716]));

        // Anything older is outside the window, whatever its count.
        self::assertSame(0, $this->globalLevelFor($config, [(string) ($minute - 6) => 100000]));

        // Every minute of a dense window keeps its own weight: 100 * Σ(0.85..1.05)^8 ≈ 384 → level 2.
        $dense = [];
        for ($age = 0; $age <= 4; $age++) {
            $dense[(string) ($minute - $age)] = 100;
        }
        self::assertSame(2, $this->globalLevelFor(new RateLimitConfig(level1ThresholdGlobal: 120, level2ThresholdGlobal: 300, level3ThresholdGlobal: 600), $dense));
    }

    public function testRaisedGlobalLevelIsHeldDuringGracePeriodAfterTrafficDrops(): void
    {
        $config = new RateLimitConfig(levelDecayGracePeriod: 300);

        $held = new InMemoryThreatStateStore();
        $held->global = ['current_level' => 3, 'level_last_changed' => \time() - 1000, 'below_since' => \time() - 10, 'traffic_buckets' => []];
        self::assertSame(3, (new ThreatAwarenessManager($config, $held))->getGlobalThreatLevel());

        $decayed = new InMemoryThreatStateStore();
        $decayed->global = ['current_level' => 3, 'level_last_changed' => \time() - 1000, 'below_since' => \time() - 301, 'traffic_buckets' => []];
        self::assertSame(0, (new ThreatAwarenessManager($config, $decayed))->getGlobalThreatLevel());
    }

    public function testLongElevatedLevelIsHeldWhenTrafficDropsOnlyNow(): void
    {
        $config = new RateLimitConfig(levelDecayGracePeriod: 300, gcProbability: 0);
        $store = new InMemoryThreatStateStore();
        // Raised long before the grace period, but the traffic drop is first seen by this request.
        $store->global = ['current_level' => 3, 'level_last_changed' => \time() - 1000, 'traffic_buckets' => []];

        self::assertSame(3, (new ThreatAwarenessManager($config, $store))->getGlobalThreatLevel());

        $request = Requests::make();
        $request->context->fingerprint = self::FP;
        $manager = new ThreatAwarenessManager($config, $store);
        $manager->recordRequest($request);

        self::assertSame(3, $manager->getGlobalThreatLevel());
        self::assertSame(3, $store->global['current_level']);
        self::assertEqualsWithDelta(\time(), $store->global['below_since'], 2, 'the drop is timestamped now');
    }

    public function testHeldLevelDecaysOnceGraceHasPassedSinceTheDrop(): void
    {
        $config = new RateLimitConfig(levelDecayGracePeriod: 300, gcProbability: 0);
        $store = new InMemoryThreatStateStore();
        $store->global = ['current_level' => 3, 'level_last_changed' => \time() - 1000, 'below_since' => \time() - 301, 'traffic_buckets' => []];

        $request = Requests::make();
        $request->context->fingerprint = self::FP;
        $manager = new ThreatAwarenessManager($config, $store);
        $manager->recordRequest($request);

        self::assertSame(0, $manager->getGlobalThreatLevel());
        self::assertSame(0, $store->global['current_level']);
        self::assertNull($store->global['below_since']);
    }

    public function testBelowSinceIsClearedWhenTrafficRisesAgain(): void
    {
        $config = new RateLimitConfig(level1ThresholdGlobal: 120, level2ThresholdGlobal: 300, gcProbability: 0);
        $store = new InMemoryThreatStateStore();
        $store->global = [
            'current_level' => 1,
            'level_last_changed' => \time() - 100,
            'below_since' => \time() - 50,
            'traffic_buckets' => [(string) \intdiv(\time(), 60) => 204], // +1 → ≈ 302.9, level 2
        ];

        $request = Requests::make();
        $request->context->fingerprint = self::FP;
        $manager = new ThreatAwarenessManager($config, $store);
        $manager->recordRequest($request);

        self::assertSame(2, $store->global['current_level']);
        self::assertNull($store->global['below_since']);
    }

    public function testGracePeriodStartsWhenTrafficDropsNotWhenLevelRose(): void
    {
        $t = 1_700_000_000;
        $clock = static function () use (&$t): int {
            return $t;
        };
        $config = new RateLimitConfig(level1ThresholdGlobal: 120, level2ThresholdGlobal: 300, levelDecayGracePeriod: 300, gcProbability: 0);
        $store = new InMemoryThreatStateStore();
        $store->global = ['current_level' => 0, 'level_last_changed' => 0, 'traffic_buckets' => [(string) \intdiv($t, 60) => 300]];

        $request = Requests::make();
        $request->context->fingerprint = self::FP;

        $record = static function () use ($config, $store, $clock, $request): int {
            $manager = new ThreatAwarenessManager($config, $store, $clock);
            $manager->recordRequest($request);

            return $manager->getGlobalThreatLevel();
        };

        self::assertSame(2, $record(), 'burst raises level 2');

        $t += 1000; // well past the grace period since the raise; traffic has vanished, the drop is seen now
        self::assertSame(2, $record(), 'held: the grace period only starts now');
        self::assertSame($t, $store->global['below_since']);

        $t += 300;
        self::assertSame(2, $record(), 'still within the grace period');

        $t += 1;
        self::assertSame(0, $record(), 'grace period over');
        self::assertNull($store->global['below_since']);
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
