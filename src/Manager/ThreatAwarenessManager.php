<?php declare(strict_types=1);

namespace GES\Botlock\Manager;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Filesystem\Sweep;
use GES\Botlock\Http\Request;
use GES\Botlock\Threat\ThreatStateStore;

/**
 * Turns raw request counts into threat levels: 0–3 globally, 0–4 per client.
 *
 * Global level: requests are counted in one-minute buckets over the current
 * and the previous five minutes, weighted so recent minutes count more, and
 * compared to the configured thresholds. Once traffic falls below the threshold of the
 * held level, that level is kept for the decay grace period before it
 * may drop.
 *
 * Individual level: plain request count per fingerprint within the
 * individual rate window, compared to the individual thresholds. Only
 * this scope reaches level 4, which blocks the client outright; a global
 * level 4 would block every visitor during a traffic spike.
 *
 * Persistence is delegated to a ThreatStateStore; when the store cannot be
 * read or written the manager stays protective and reports level 1 for the
 * affected scope instead of silently treating the traffic as zero.
 */
class ThreatAwarenessManager
{
    /** Level reported for a scope whose state could not be read or written. */
    public const UNAVAILABLE_LEVEL = 1;

    private const GLOBAL_WINDOW_SEC = 300;
    private const BUCKET_SEC = 60;

    private ?int $cachedGlobalThreatLevel = null;
    private array $cachedIndividualThreatLevels = [];
    /** @var array<string, int|null> null marks a fingerprint whose state is unavailable */
    private array $cachedIndividualRates = [];

    /**
     * @param \Closure|null $clock returns the current Unix timestamp; defaults to time(). Tests use it to move time.
     */
    public function __construct(
        private readonly RateLimitConfig $config,
        private readonly ThreatStateStore $store,
        private readonly ?\Closure $clock = null,
    ) {}

    /**
     * Records the current request for individual and, unless told
     * otherwise, global tracking.
     */
    public function recordRequest(Request $request, bool $countGlobally = true): void
    {
        if (!$this->config->isRateLimitEnabled()) {
            return;
        }

        if ($this->config->enableGlobalRateLimit && $countGlobally) {
            $this->incrementGlobalBucket();
        }

        if (!$fingerprint = $request->context->fingerprint) {
            return;
        }

        if ($this->config->enableIndividualRateLimit)
        {
            $now = $this->now();

            if (!$this->store->recordIndividual($fingerprint, $now, $now - $this->config->individualRateWindowSec)) {
                // This request is not in the count; fail closed rather than under-report.
                $this->cachedIndividualRates[$fingerprint] = null;
            }

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

        [, $level] = $this->calcOldAndNewLevelsGlobal($state, $this->now());

        return $this->cachedGlobalThreatLevel = $level;
    }

    /**
     * Requests from this fingerprint within the individual window, or null
     * when the state could not be read or this request could not be
     * recorded. Cached per request.
     */
    public function getIndividualRate(string $fingerprint): ?int
    {
        if ($fingerprint === '') {
            return 0;
        }

        if (\array_key_exists($fingerprint, $this->cachedIndividualRates)) {
            return $this->cachedIndividualRates[$fingerprint];
        }

        $windowStart = $this->now() - $this->config->individualRateWindowSec;

        return $this->cachedIndividualRates[$fingerprint] = $this->store->countIndividual($fingerprint, $windowStart);
    }

    /**
     * Threat level (0–4) for one fingerprint, derived from its request rate.
     * Unlike the global level there is no decay hold: the level follows the
     * rolling window directly. Unavailable state yields UNAVAILABLE_LEVEL.
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

        if ($rate === null) {
            return $this->cachedIndividualThreatLevels[$fingerprint] = self::UNAVAILABLE_LEVEL;
        }

        $level = match (true) {
            $this->config->isLevel4Enabled() && $rate >= $this->config->level4ThresholdIndividual => 4,
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
        $now = $this->now();

        $state = $this->store->updateGlobal(function (array $state) use ($now): array {
            $state += ['current_level' => 0, 'level_last_changed' => $now, 'below_since' => null, 'traffic_buckets' => []];

            $bucket = (string) \intdiv($now, self::BUCKET_SEC);
            $state['traffic_buckets'][$bucket] = ($state['traffic_buckets'][$bucket] ?? 0) + 1;

            $minBucket = \intdiv($now - self::GLOBAL_WINDOW_SEC, self::BUCKET_SEC);
            foreach (\array_keys($state['traffic_buckets']) as $key) {
                if ((int) $key < $minBucket) {
                    unset($state['traffic_buckets'][$key]);
                }
            }

            [$oldLevel, $newLevel, $belowSince] = $this->calcOldAndNewLevelsGlobal($state, $now);
            $state['below_since'] = $belowSince;

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
     * @param int $now the moment the buckets are evaluated for; the bucket containing it is the newest
     * @return array{0:int,1:int,2:?int} [persisted level, level to apply now, below_since to persist:
     *                                   when traffic first fell below the persisted level, null while it has not]
     */
    private function calcOldAndNewLevelsGlobal(array $state, int $now): array
    {
        $currentBucket = \intdiv($now, self::BUCKET_SEC);
        // The current minute plus the five before it, as retained by incrementGlobalBucket();
        // the oldest bucket overlaps the rolling five-minute window only partially.
        $maxAge = \intdiv(self::GLOBAL_WINDOW_SEC, self::BUCKET_SEC);

        // Weight follows the bucket's age, not its position in the array: quiet
        // minutes leave no bucket behind, so positions would collapse the gaps.
        // The current minute weighs 1.05^8 ≈ 1.48, five minutes ago 0.8^8 ≈ 0.17.
        $score = 0.0;
        foreach ($state['traffic_buckets'] ?? [] as $key => $count) {
            $age = $currentBucket - (int) $key;

            if ($age > $maxAge) {
                continue; // outside the window; only seen on the read path before the next prune
            }

            $score += $count * (1.05 - 0.05 * \max(0, $age)) ** 8;
        }

        $newLevel = match (true) {
            $score >= $this->config->level3ThresholdGlobal => 3,
            $score >= $this->config->level2ThresholdGlobal => 2,
            $score >= $this->config->level1ThresholdGlobal => 1,
            default => 0,
        };

        $oldLevel = (int) ($state['current_level'] ?? 0);

        if ($newLevel >= $oldLevel) {
            return [$oldLevel, $newLevel, null];
        }

        // Traffic is below the held level: the grace period runs from the first
        // evaluation that saw the drop, not from when the level was raised.
        $belowSince = isset($state['below_since']) ? (int) $state['below_since'] : $now;

        if ($now - $belowSince <= $this->config->levelDecayGracePeriod) {
            return [$oldLevel, $oldLevel, $belowSince];
        }

        return [$oldLevel, $newLevel, null];
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : \time();
    }

    /**
     * Runs the store's garbage collection on roughly one request in
     * BOTLOCK_GC_PROBABILITY; 0 disables it.
     */
    private function maybeCollectGarbage(int $now): void
    {
        if (!Sweep::isDue($this->config->gcProbability)) {
            return;
        }

        $this->store->collectGarbage($now - $this->config->individualRateWindowSec);
    }
}
