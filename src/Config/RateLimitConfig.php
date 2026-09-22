<?php declare(strict_types=1);

namespace GES\Botlock\Config;

/**
 * Thresholds and switches for the rate-based threat evaluation.
 */
final readonly class RateLimitConfig
{
    public function __construct(
        /** Fixed threat level that replaces rate evaluation entirely when set. */
        public ?int $threatLevelOverride = null,
        public bool $enableGlobalRateLimit = true,
        public bool $enableIndividualRateLimit = true,
        /** Master switch; only effective while at least one of the two limits above is enabled. */
        public bool $enableRateLimit = true,
        /** Weighted five-minute global scores that activate levels 1–3. */
        public int  $level1ThresholdGlobal = 120,
        public int  $level2ThresholdGlobal = 300,
        public int  $level3ThresholdGlobal = 600,
        /** Seconds to hold a raised global level after traffic drops below its threshold. */
        public int  $levelDecayGracePeriod = 300,
        /** Rolling window in seconds for per-client request counting. */
        public int  $individualRateWindowSec = 60,
        /** Requests per individual window that activate levels 1–3. */
        public int  $level1ThresholdIndividual = 60,
        public int  $level2ThresholdIndividual = 90,
        public int  $level3ThresholdIndividual = 120,
        /** One request in this many sweeps stale per-client state; 0 disables. */
        public int  $gcProbability = 1000,
    ) {}

    public static function fromEnv(): self
    {
        $override = Env::get('THREAT_LEVEL_OVERRIDE');

        return new self(
            threatLevelOverride: \is_null($override) ? null : (int) $override,
            enableGlobalRateLimit: (bool) Env::bool('ENABLE_GLOBAL_RATE_LIMIT', true),
            enableIndividualRateLimit: (bool) Env::bool('ENABLE_INDIVIDUAL_RATE_LIMIT', true),
            enableRateLimit: (bool) Env::bool('ENABLE_RATE_LIMIT', true),
            level1ThresholdGlobal: Env::int('LEVEL_1_THRESHOLD_GLOBAL', 120),
            level2ThresholdGlobal: Env::int('LEVEL_2_THRESHOLD_GLOBAL', 300),
            level3ThresholdGlobal: Env::int('LEVEL_3_THRESHOLD_GLOBAL', 600),
            levelDecayGracePeriod: Env::int('LEVEL_DECAY_GRACE_PERIOD', 300),
            individualRateWindowSec: Env::int('INDIVIDUAL_RATE_WINDOW_SEC', 60),
            level1ThresholdIndividual: Env::int('LEVEL_1_THRESHOLD_INDIVIDUAL', 60),
            level2ThresholdIndividual: Env::int('LEVEL_2_THRESHOLD_INDIVIDUAL', 90),
            level3ThresholdIndividual: Env::int('LEVEL_3_THRESHOLD_INDIVIDUAL', 120),
            gcProbability: \max(0, Env::int('GC_PROBABILITY', 1000)),
        );
    }

    /** True when rate evaluation runs at all. */
    public function isRateLimitEnabled(): bool
    {
        return $this->enableRateLimit && ($this->enableGlobalRateLimit || $this->enableIndividualRateLimit);
    }
}
