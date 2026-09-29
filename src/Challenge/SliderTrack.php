<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

/**
 * The recorded movement of the slider, as the challenge page reports it,
 * and the rules that judge it. No trained model: plain checks against the
 * cheapest ways to fake a solve (an instant answer, a jump to the target,
 * a straight line at constant speed, timer-driven events).
 *
 * The track is a list of entries in time order, times in milliseconds
 * since the puzzle was shown:
 * - {k: "p", pt, t0, pts: [[dt, v, dy], …], co}: a drag, with pointer type,
 *   start time, one sample per value change (time since t0, slider value,
 *   vertical drift in puzzle pixels) and the number of pointer movements
 *   the browser reported, coalesced ones included;
 * - {k: "k", t, v, r}: a key press that moved the slider, r for auto-repeat;
 * - {k: "c", t, d, v}: a press on the handle that barely dragged, d long.
 *
 * The page only lets the handle be dragged, never jump to a press on the
 * track, so the value never leaps except by a key to a page or an end.
 * All of it comes from the client, so a determined attacker can forge a
 * plausible track; the rules raise the cost, they do not prove a person.
 * Key presses are judged by weaker rules and answered with a harder proof
 * of work instead of being refused, so the slider stays usable without a
 * pointer; a pointer stroke that moves farther than a key can is always
 * judged as a drag, never as a press.
 */
final readonly class SliderTrack
{
    /** Most samples the page records; more is not a person dragging. */
    public const MAX_SAMPLES = 2000;

    /** Server time from the puzzle's render to reporting below which nobody saw the picture. */
    private const MIN_ELAPSED_MS = 800;

    /** Clock slack when comparing the track's duration with the server's. */
    private const CLOCK_SLACK_MS = 250;

    /** Reaction time before the first movement; faster is no person looking at the puzzle. */
    private const MIN_REACTION_MS = 200;

    /**
     * Share of the final position a single drag has to cover to count as
     * dragging, and share of that drag's length from which another stroke
     * counts as a second drag instead of a correction.
     */
    private const DRAG_SHARE = 0.7;

    private const MIN_DRAG_SAMPLES = 8;
    private const MIN_DRAG_MS = 300;
    private const MAX_DRAG_MS = 30_000;

    /** Fastest plausible movement per animation frame, in slider pixels. */
    private const MAX_STEP_PER_FRAME = 40;
    private const FRAME_MS = 16;

    /**
     * Most frames one step is credited with. The page samples on value
     * changes, so after a hold or a stop the next step is small; a busy
     * page may still merge a few frames of movement into one.
     */
    private const MAX_CREDIT_FRAMES = 3;

    /** Largest vertical drift of the pointer while dragging, in puzzle pixels. */
    private const MAX_DRIFT = 80;

    /** Minimum coefficient of variation of the drag speed; a steady glide is a script. */
    private const MIN_SPEED_VARIATION = 0.25;

    /** Share at the end of a drag where the peak speed must not lie: people slow down onto a target. */
    private const PEAK_TAIL = 0.1;

    /** Smallest spread of the sample intervals; identical intervals are a timer. */
    private const MIN_INTERVAL_SPREAD_MS = 0.5;

    /** Shortest time between two separate key presses or presses on the handle. */
    private const MIN_ACTION_GAP_MS = 40;

    /** Tolerance within which three or more gaps between actions count as identical. */
    private const SAME_GAP_MS = 1.0;

    /** Shortest delay before a held key starts repeating. */
    private const MIN_REPEAT_DELAY_MS = 150;

    /** Shortest press on the handle. */
    private const MIN_PRESS_MS = 20;

    private const POINTER_TYPES = ['mouse', 'pen', 'touch'];

    /**
     * @param list<array{k: string, t: float, end: float, from: int, v: int, pt?: string, pts?: list<array{float, int, float}>, r?: bool}> $entries
     */
    private function __construct(private array $entries) {}

    /**
     * Validates the submitted track; null when its shape is off.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || !\array_is_list($data) || $data === []) {
            return null;
        }

        $max = SliderPuzzle::WIDTH - SliderPuzzle::PIECE;
        $entries = [];
        $samples = 0;
        $previousEnd = 0.0;
        $value = 0;

        foreach ($data as $item) {
            $entry = match (\is_array($item) ? ($item['k'] ?? null) : null) {
                'p' => self::stroke($item, $max),
                'k' => self::time($item['t'] ?? null) !== null && self::value($item['v'] ?? null, $max) !== null
                    && \is_bool($item['r'] ?? false)
                    ? ['k' => 'k', 't' => (float) $item['t'], 'end' => (float) $item['t'], 'v' => $item['v'], 'r' => $item['r'] ?? false]
                    : null,
                'c' => self::time($item['t'] ?? null) !== null && self::time($item['d'] ?? null) !== null
                    && self::value($item['v'] ?? null, $max) !== null
                    ? ['k' => 'c', 't' => (float) $item['t'], 'end' => (float) $item['t'] + (float) $item['d'], 'v' => $item['v']]
                    : null,
                default => null,
            };

            if ($entry === null || $entry['t'] < $previousEnd) {
                return null;
            }

            $samples += isset($entry['pts']) ? \count($entry['pts']) : 1;
            if ($samples > self::MAX_SAMPLES) {
                return null;
            }

            $previousEnd = $entry['end'];
            $entries[] = $entry + ['from' => $value];
            $value = $entry['v'];
        }

        return new self($entries);
    }

    /**
     * @param int   $pos       the reported final position, already checked against the target
     * @param float $elapsedMs server time between rendering the puzzle and this report
     */
    public function judge(int $pos, float $elapsedMs): SliderVerdict
    {
        $last = $this->entries[\array_key_last($this->entries)];

        if ($elapsedMs < self::MIN_ELAPSED_MS
            || $last['end'] > $elapsedMs + self::CLOCK_SLACK_MS
            || $this->entries[0]['t'] < self::MIN_REACTION_MS
            || $last['v'] !== $pos
            || !$this->stepsArePlausible())
        {
            return SliderVerdict::Rejected;
        }

        // One stroke did most of the work. The other strokes are measured
        // against that drag, not the target: pulling back after an overshoot
        // is a correction, but a stroke nearly as long as the main one is a
        // second drag and has to look human too.
        $longest = $this->longestStroke();
        if ($longest >= self::DRAG_SHARE * $pos) {
            return $this->strokesAreHuman(self::DRAG_SHARE * $longest) ? SliderVerdict::Drag : SliderVerdict::Rejected;
        }

        // No single stroke did the work: one that moved farther than a key
        // can is judged as a drag, never counted as a press.
        if (!$this->strokesAreHuman(self::page() + 1)) {
            return SliderVerdict::Rejected;
        }

        return $this->actionsArePlausible() ? SliderVerdict::Assisted : SliderVerdict::Rejected;
    }

    /**
     * No value change the input could not have made: a key moves the
     * slider by one, by a page (a tenth of the range) or to either end, and
     * a pointer only drags the handle, never faster than a hand, from
     * where it was; a pause earns no extra distance.
     */
    private function stepsArePlausible(): bool
    {
        $max = SliderPuzzle::WIDTH - SliderPuzzle::PIECE;
        $page = self::page();

        foreach ($this->entries as $entry) {
            $plausible = match ($entry['k']) {
                'k' => \abs($entry['v'] - $entry['from']) <= $page || \in_array($entry['v'], [0, $max], true),
                'c' => \abs($entry['v'] - $entry['from']) <= 2 * self::MAX_STEP_PER_FRAME,
                'p' => self::dragsSmoothly($entry),
            };

            if (!$plausible) {
                return false;
            }
        }

        return true;
    }

    /**
     * The farthest one key press moves the slider short of an end: a page,
     * a tenth of the range, rounded up, plus one.
     */
    private static function page(): int
    {
        return (int) \ceil((SliderPuzzle::WIDTH - SliderPuzzle::PIECE) / 10) + 1;
    }

    /**
     * @param array{from: int, pts: list<array{float, int, float}>} $drag
     */
    private static function dragsSmoothly(array $drag): bool
    {
        [$time, $value] = [0.0, $drag['from']];

        foreach ($drag['pts'] as [$dt, $v]) {
            $credit = \min(\max($dt - $time, self::FRAME_MS), self::MAX_CREDIT_FRAMES * self::FRAME_MS);

            if (\abs($v - $value) > self::MAX_STEP_PER_FRAME * $credit / self::FRAME_MS) {
                return false;
            }
            [$time, $value] = [$dt, $v];
        }

        return true;
    }

    /**
     * The farthest a single pointer stroke moved the slider.
     */
    private function longestStroke(): int
    {
        $longest = 0;

        foreach ($this->entries as $entry) {
            if ($entry['k'] === 'p') {
                $longest = \max($longest, \abs($entry['v'] - $entry['from']));
            }
        }

        return $longest;
    }

    /**
     * Whether every pointer stroke that moved the slider at least $distance
     * passes isHumanDrag(); a long stroke is never excused by another one.
     */
    private function strokesAreHuman(float $distance): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry['k'] === 'p' && \abs($entry['v'] - $entry['from']) >= $distance && !$this->isHumanDrag($entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{pt: string, pts: list<array{float, int, float}>} $drag
     */
    private function isHumanDrag(array $drag): bool
    {
        $pts = $drag['pts'];
        $count = \count($pts);
        $duration = $pts[$count - 1][0];

        if ($count < self::MIN_DRAG_SAMPLES || $duration < self::MIN_DRAG_MS || $duration > self::MAX_DRAG_MS) {
            return false;
        }

        $intervals = [];
        $speeds = [];
        $drifts = [];

        // Between samples only: the first one's time since the press includes the reaction.
        for ($i = 1; $i < $count; $i++) {
            $interval = $pts[$i][0] - $pts[$i - 1][0];
            $intervals[] = $interval;
            $speeds[] = \abs($pts[$i][1] - $pts[$i - 1][1]) / \max($interval, 1.0);
        }

        foreach ($pts as [, , $dy]) {
            if (\abs($dy) > self::MAX_DRIFT) {
                return false;
            }
            $drifts[(string) $dy] = true;
        }

        // A hand never holds a mouse or pen perfectly level; touch screens may report it so.
        if ($drag['pt'] !== 'touch' && \count($drifts) < 2) {
            return false;
        }

        if (\max($intervals) - \min($intervals) < self::MIN_INTERVAL_SPREAD_MS) {
            return false;
        }

        $mean = \array_sum($speeds) / \count($speeds);
        if ($mean <= 0 || self::deviation($speeds, $mean) / $mean < self::MIN_SPEED_VARIATION) {
            return false;
        }

        $peak = \array_search(\max($speeds), $speeds, true);
        $tail = \max(1, (int) \round(\count($speeds) * self::PEAK_TAIL));

        return $peak < \count($speeds) - $tail;
    }

    /**
     * Keys and presses: at least one deliberate action, spaced like a
     * person pressing, and auto-repeat only after a held key's delay.
     */
    private function actionsArePlausible(): bool
    {
        $times = [];
        $lastPress = null;

        foreach ($this->entries as $entry) {
            if ($entry['k'] === 'k' && $entry['r']) {
                if ($lastPress === null || $entry['t'] - $lastPress < self::MIN_REPEAT_DELAY_MS) {
                    return false;
                }
                continue;
            }

            if ($entry['k'] !== 'k' && $entry['end'] - $entry['t'] < self::MIN_PRESS_MS) {
                return false;
            }

            $times[] = $lastPress = $entry['t'];
        }

        if ($times === []) {
            return false;
        }

        $gaps = [];
        for ($i = 1, $n = \count($times); $i < $n; $i++) {
            $gaps[] = $times[$i] - $times[$i - 1];
        }

        if ($gaps !== [] && \min($gaps) < self::MIN_ACTION_GAP_MS) {
            return false;
        }

        return \count($gaps) < 2 || \max($gaps) - \min($gaps) > self::SAME_GAP_MS;
    }

    /**
     * @return array{k: string, t: float, end: float, v: int, pt: string, pts: list<array{float, int, float}>}|null
     */
    private static function stroke(array $item, int $max): ?array
    {
        // The value only changes on a movement, which the page counts
        // first; only the press may move the handle once without one, when
        // it lands just beside it.
        if (!\in_array($item['pt'] ?? null, self::POINTER_TYPES, true)
            || ($t0 = self::time($item['t0'] ?? null)) === null
            || !\is_array($item['pts'] ?? null) || !\array_is_list($item['pts']) || $item['pts'] === []
            || !\is_int($item['co'] ?? null) || $item['co'] < \count($item['pts']) - 1)
        {
            return null;
        }

        $pts = [];
        $previous = 0.0;

        foreach ($item['pts'] as $point) {
            if (!\is_array($point) || !\array_is_list($point) || \count($point) !== 3
                || ($dt = self::time($point[0])) === null || $dt < $previous
                || ($v = self::value($point[1], $max)) === null
                || !\is_int($point[2]) && !\is_float($point[2]) || !\is_finite((float) $point[2]))
            {
                return null;
            }

            $pts[] = [$dt, $v, (float) $point[2]];
            $previous = $dt;
        }

        return ['k' => 'p', 't' => $t0, 'end' => $t0 + $previous, 'v' => $pts[\count($pts) - 1][1], 'pt' => $item['pt'], 'pts' => $pts];
    }

    private static function time(mixed $value): ?float
    {
        return (\is_int($value) || \is_float($value)) && \is_finite((float) $value) && $value >= 0 ? (float) $value : null;
    }

    private static function value(mixed $value, int $max): ?int
    {
        return \is_int($value) && $value >= 0 && $value <= $max ? $value : null;
    }

    /**
     * @param list<float> $values
     */
    private static function deviation(array $values, float $mean): float
    {
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return \sqrt($sum / \count($values));
    }
}
