<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Support;

/**
 * Slider tracks in the format the challenge page records (see
 * SliderTrack): a human-like drag and the cheap fakes it must tell apart.
 * Times are milliseconds since the puzzle was shown.
 */
final class Tracks
{
    /**
     * A drag along a minimum-jerk curve (the smooth bell-shaped speed of a
     * reaching hand) sampled once per frame with jitter, drifting a little
     * vertically; the slider only reports samples whose value changed.
     */
    public static function humanDrag(int $target, float $t0 = 600.0, int $durationMs = 900, string $pointer = 'mouse', int $seed = 1): array
    {
        \mt_srand($seed);
        $pts = [];
        $dy = 0.0;
        $previous = null;

        for ($t = 0.0; $t < $durationMs; $t += 16.7 + \mt_rand(-8, 8) / 10) {
            $tau = $t / $durationMs;
            $v = (int) \round($target * (10 * $tau ** 3 - 15 * $tau ** 4 + 6 * $tau ** 5));
            $dy = \round($dy + \mt_rand(-10, 10) / 10, 1);

            if ($v !== $previous) {
                $pts[] = [\round($t, 1), $v, $dy];
                $previous = $v;
            }
        }

        if ($previous !== $target) {
            $pts[] = [(float) $durationMs, $target, $dy];
        }

        return [['k' => 'p', 'pt' => $pointer, 't0' => $t0, 'pts' => $pts, 'co' => \count($pts) * 2]];
    }

    /**
     * A script's drag: the same step at a fixed interval, perfectly level.
     */
    public static function linearDrag(int $target, float $t0 = 600.0): array
    {
        $pts = [];
        for ($v = 5, $t = 0; $v < $target; $v += 5, $t += 10) {
            $pts[] = [(float) $t, $v, 0];
        }
        $pts[] = [(float) $t, $target, 0];

        return [['k' => 'p', 'pt' => 'mouse', 't0' => $t0, 'pts' => $pts, 'co' => \count($pts)]];
    }

    /**
     * Holding the right arrow key: one press, then auto-repeat after the
     * initial delay at the repeat rate.
     */
    public static function keyboard(int $target, float $t0 = 700.0): array
    {
        $track = [['k' => 'k', 't' => $t0, 'v' => 1, 'r' => false]];
        for ($v = 2, $t = $t0 + 500; $v <= $target; $v++, $t += 33.3) {
            $track[] = ['k' => 'k', 't' => \round($t, 1), 'v' => $v, 'r' => true];
        }

        return $track;
    }

    /**
     * One press on the track right where the gap is, jumping the handle
     * there: the page blocks it, so only a script reports it.
     */
    public static function trackPress(int $target, float $t = 900.0): array
    {
        return [['k' => 'c', 't' => $t, 'd' => 85.0, 'v' => $target]];
    }

    /**
     * The end time of a track, for picking a plausible server elapsed time.
     */
    public static function end(array $track): float
    {
        $last = $track[\array_key_last($track)];

        return match ($last['k']) {
            'p' => $last['t0'] + $last['pts'][\array_key_last($last['pts'])][0],
            'c' => $last['t'] + $last['d'],
            default => $last['t'],
        };
    }
}
