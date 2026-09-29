<?php declare(strict_types=1);

namespace GES\Botlock\Filesystem;

/**
 * The odds of a sweep of stale state files: requests take turns paying for
 * the cleanup instead of a cron job, roughly one in BOTLOCK_GC_PROBABILITY.
 */
final class Sweep
{
    /**
     * Whether this call sweeps: one in $oneIn on average; never at 0 or below.
     */
    public static function isDue(int $oneIn): bool
    {
        return $oneIn > 0 && \random_int(1, $oneIn) === 1;
    }
}
