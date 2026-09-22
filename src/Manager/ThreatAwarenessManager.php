<?php declare(strict_types=1);

namespace GES\Botlock\Manager;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Http\Request;
use GES\Botlock\Threat\ThreatStateStore;

/**
 * Turns raw request counts into threat levels 0–3.
 *
 * Global level: requests are counted in one-minute buckets over the last
 * five minutes, weighted so recent minutes count more, and compared to the
 * configured thresholds. Once raised, a level is held for the decay grace
 * period before it may drop again.
 *
 * Individual level: plain request count per fingerprint within the
 * individual rate window, compared to the individual thresholds.
 *
 * Persistence is delegated to a ThreatStateStore; when the store cannot be
 * read the manager stays protective and reports level 1.
 */
class ThreatAwarenessManager
{
    private const GLOBAL_WINDOW_SEC = 300;
    private const BUCKET_SEC = 60;
    private const UNAVAILABLE_LEVEL = 1;

    private ?int $cachedGlobalThreatLevel = null;
    private array $cachedIndividualThreatLevels = [];
    private array $cachedIndividualRates = [];

    public function __construct(
        private readonly RateLimitConfig $config,
        private readonly ThreatStateStore $store,
    ) {}

    /**
     * Records the current request for both global and individual tracking.
     */
    public function recordRequest(Request $request): void
    {
        if (!$this->config->isRateLimitEnabled()) {
            return;
        }

        if ($this->config->enableGlobalRateLimit) {
            $this->incrementGlobalBucket();
        }

        if (!$fingerprint = $request->context->fingerprint) {
            return;
        }

        if ($this->config->enableIndividualRateLimit)
        {
            $now = \time();
            $this->store->recordIndividual($fingerprint, $now, $now - $this->config->individualRateWindowSec);
            $this->maybeCollectGarbage($now);
        }
    }

    /**
     * Current global threat level. Cached per request.
     */
    public function getGlobalThreatLevel(): int
    {
        if ($this->cachedGlobalThreatLevel !== null) {
            return $this->cachedGlobalThreatLevel;
        }

        $state = $this->store->readGlobal();

        if ($state === null) {
            return $this->cachedGlobalThreatLevel = self::UNAVAILABLE_LEVEL;
        }

        [, $level] = $this->calcOldAndNewLevelsGlobal($state);

        return $this->cachedGlobalThreatLevel = $level;
    }

    /**
     * Requests from this fingerprint within the individual window. Cached per request.
     */
    public function getIndividualRate(string $fingerprint): int
    {
        if ($fingerprint === '') {
            return 0;
        }

        if (isset($this->cachedIndividualRates[$fingerprint])) {
            return $this->cachedIndividualRates[$fingerprint];
        }

        $windowStart = \time() - $this->config->individualRateWindowSec;
        $rate = $this->store->countIndividual($fingerprint, $windowStart) ?? 0;

        return $this->cachedIndividualRates[$fingerprint] = $rate;
    }

    /**
     * Threat level (0–3) for one fingerprint, derived from its request rate.
     * Unlike the global level there is no decay hold: the level follows the
     * rolling window directly.
     */
    public function getIndividualThreatLevel(string $fingerprint): int
    {
        if ($fingerprint === '') {
            return self::UNAVAILABLE_LEVEL;
        }

        if (isset($this->cachedIndividualThreatLevels[$fingerprint])) {
            return $this->cachedIndividualThreatLevels[$fingerprint];
        }

        $rate = $this->getIndividualRate($fingerprint);

        $level = match (true) {
            $rate >= $this->config->level3ThresholdIndividual => 3,
            $rate >= $this->config->level2ThresholdIndividual => 2,
            $rate >= $this->config->level1ThresholdIndividual => 1,
            default => 0,
        };

        return $this->cachedIndividualThreatLevels[$fingerprint] = $level;
    }

    /**
     * Adds this request to the current global bucket, prunes buckets outside
     * the window and re-evaluates the persisted level, all under one lock.
     */
    private function incrementGlobalBucket(): void
    {
        $now = \time();

        $state = $this->store->updateGlobal(function (array $state) use ($now): array {
            $state += ['current_level' => 0, 'level_last_changed' => $now, 'traffic_buckets' => []];

            $bucket = (string) \intdiv($now, self::BUCKET_SEC);
            $state['traffic_buckets'][$bucket] = ($state['traffic_buckets'][$bucket] ?? 0) + 1;

            $minBucket = \intdiv($now - self::GLOBAL_WINDOW_SEC, self::BUCKET_SEC);
            foreach (\array_keys($state['traffic_buckets']) as $key) {
                if ((int) $key < $minBucket) {
                    unset($state['traffic_buckets'][$key]);
                }
            }

            [$oldLevel, $newLevel] = $this->calcOldAndNewLevelsGlobal($state);

            if ($newLevel !== $oldLevel) {
                $state['current_level'] = $newLevel;
                $state['level_last_changed'] = $now;
            }

            return $state;
        });

        $this->cachedGlobalThreatLevel = $state === null
            ? self::UNAVAILABLE_LEVEL
            : (int) ($state['current_level'] ?? 0);
    }

    /**
     * @return array{0:int,1:int} [previously persisted level, level the buckets warrant now]
     */
    private function calcOldAndNewLevelsGlobal(array $state): array
    {
        $buckets = $state['traffic_buckets'] ?? [];
        \ksort($buckets);
        $buckets = \array_values($buckets);
        $amount = \count($buckets);

        // Newest bucket weighs 1.05^8 ≈ 1.48, each older minute a bit less.
        $weight = static fn(int $index): float => (-0.05 * (($amount - 1) - $index) + 1.05) ** 8;

        $score = 0.0;
        foreach ($buckets as $index => $count) {
            $score += $count * $weight($index);
        }

        $newLevel = match (true) {
            $score >= $this->config->level3ThresholdGlobal => 3,
            $score >= $this->config->level2ThresholdGlobal => 2,
            $score >= $this->config->level1ThresholdGlobal => 1,
            default => 0,
        };

        $oldLevel = (int) ($state['current_level'] ?? 0);
        $lastChanged = (int) ($state['level_last_changed'] ?? 0);

        // Hold a raised level for the grace period before letting it decay.
        if ($newLevel < $oldLevel && (\time() - $lastChanged) <= $this->config->levelDecayGracePeriod) {
            $newLevel = $oldLevel;
        }

        return [$oldLevel, $newLevel];
    }

    /**
     * Runs the store's garbage collection on roughly one request in
     * BOTLOCK_GC_PROBABILITY; 0 disables it.
     */
    private function maybeCollectGarbage(int $now): void
    {
        $probability = $this->config->gcProbability;

        if ($probability <= 0 || \random_int(1, $probability) !== 1) {
            return;
        }

        $this->store->collectGarbage($now - $this->config->individualRateWindowSec);
    }
}
