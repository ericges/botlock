<?php declare(strict_types=1);

namespace GES\Botlock\Config;

/**
 * Thresholds and switches for the rate-based threat evaluation.
 */
final readonly class RateLimitConfig
{
    /** Highest threat level; only the individual rate can reach it. */
    public const MAX_LEVEL = 4;

    public function __construct(
        /** Fixed threat level (clamped to 0–4) that replaces rate evaluation entirely when set. */
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
        /**
         * Requests per individual window that activate level 4: answered with 429, no challenge.
         * Off when 0 or not above the level 3 threshold, see isLevel4Enabled().
         */
        public int  $level4ThresholdIndividual = 180,
        /** One request in this many sweeps stale per-client state; 0 disables. */
        public int  $gcProbability = 1000,
    ) {}

    public static function fromEnv(): self
    {
        $override = Env::get('THREAT_LEVEL_OVERRIDE');

        return new self(
            threatLevelOverride: \is_null($override) ? null : \min(self::MAX_LEVEL, \max(0, (int) $override)),
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
            level4ThresholdIndividual: Env::int('LEVEL_4_THRESHOLD_INDIVIDUAL', 180),
            gcProbability: \max(0, Env::int('GC_PROBABILITY', 1000)),
        );
    }

    /**
     * True when the individual rate can reach level 4. A threshold at or below
     * level 3's would block clients before they are ever challenged at level 3,
     * which is what a deployment that raised its thresholds before level 4
     * existed would get from the default, so level 4 stays off then.
     */
    public function isLevel4Enabled(): bool
    {
        return $this->level4ThresholdIndividual > 0 && $this->level4ThresholdIndividual > $this->level3ThresholdIndividual;
    }

    /** True when rate evaluation runs at all. */
    public function isRateLimitEnabled(): bool
    {
        return $this->enableRateLimit && ($this->enableGlobalRateLimit || $this->enableIndividualRateLimit);
    }
}
