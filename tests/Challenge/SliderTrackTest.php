<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\SliderTrack;
use GES\Botlock\Challenge\SliderVerdict;
use GES\Botlock\Tests\Support\Tracks;
use PHPUnit\Framework\TestCase;

final class SliderTrackTest extends TestCase
{
    private const TARGET = 150;

    public function testHumanDragsPass(): void
    {
        foreach ([1, 2, 3, 4, 5] as $seed) {
            foreach (['mouse', 'pen', 'touch'] as $pointer) {
                self::assertSame(SliderVerdict::Drag, self::judge(Tracks::humanDrag(self::TARGET, pointer: $pointer, seed: $seed)), "$pointer, seed $seed");
            }
        }

        self::assertSame(SliderVerdict::Drag, self::judge(Tracks::humanDrag(self::TARGET, durationMs: 400)), 'a quick drag');
        self::assertSame(SliderVerdict::Drag, self::judge(Tracks::humanDrag(self::TARGET, durationMs: 4000)), 'a slow drag');
    }

    public function testADragFineTunedByKeysStillCountsAsDrag(): void
    {
        $track = Tracks::humanDrag(self::TARGET - 3);
        $end = Tracks::end($track);
        $track[] = ['k' => 'k', 't' => $end + 400, 'v' => self::TARGET - 2, 'r' => false];
        $track[] = ['k' => 'k', 't' => $end + 650, 'v' => self::TARGET - 1, 'r' => false];
        $track[] = ['k' => 'k', 't' => $end + 830, 'v' => self::TARGET, 'r' => false];

        self::assertSame(SliderVerdict::Drag, self::judge($track));
    }

    public function testACorrectiveSecondDragStillCountsAsDrag(): void
    {
        $track = Tracks::humanDrag(self::TARGET - 7);
        $track[] = self::nudge(self::TARGET - 7, self::TARGET, Tracks::end($track) + 500);

        self::assertSame(SliderVerdict::Drag, self::judge($track));
    }

    public function testTheLongestDragIsJudgedNotTheLast(): void
    {
        $track = Tracks::linearDrag(self::TARGET - 4);
        $track[] = self::nudge(self::TARGET - 4, self::TARGET, Tracks::end($track) + 500);

        self::assertSame(SliderVerdict::Rejected, self::judge($track), 'a scripted drag is not excused by a small last one');
    }

    public function testTwoHalfDragsAreAssisted(): void
    {
        $track = Tracks::humanDrag(75);
        $second = Tracks::humanDrag(75, seed: 2)[0];
        $second['t0'] = Tracks::end($track) + 500;
        $second['pts'] = \array_map(fn(array $p): array => [$p[0], $p[1] + 75, $p[2]], $second['pts']);
        $track[] = $second;

        self::assertSame(SliderVerdict::Assisted, self::judge($track), 'neither drag covers most of the way');
    }

    public function testAScriptedLongDragIsNotExcusedByAnEarlierHumanOne(): void
    {
        // Human drag 0→140 (140 pixels, passes isHumanDrag; long drag ≥ 0.7×150 = 105)
        $track = Tracks::humanDrag(140);

        // Correction backward 140→40 (100 pixels, not a long drag; just a correction)
        $track[] = self::linearDragFrom(140, 40, Tracks::end($track) + 500);

        // Scripted linear drag 40→150 (110 pixels, is a long drag, but constant speed and zero drift fail isHumanDrag)
        $track[] = self::linearDragFrom(40, 150, Tracks::end($track) + 500);

        self::assertSame(SliderVerdict::Rejected, self::judge($track), 'every long drag must be human; the final linear one fails');
    }

    public function testKeyboardSolvesAreAssisted(): void
    {
        self::assertSame(SliderVerdict::Assisted, self::judge(Tracks::keyboard(self::TARGET)));

        $nudged = Tracks::keyboard(self::TARGET - 3);
        $nudged[] = ['k' => 'c', 't' => Tracks::end($nudged) + 600, 'd' => 90.0, 'v' => self::TARGET];
        self::assertSame(SliderVerdict::Assisted, self::judge($nudged), 'keys, then a nudge of the handle');
    }

    public function testJumpsToAPressOnTheTrackAreRejected(): void
    {
        self::assertSame(SliderVerdict::Rejected, self::judge(Tracks::trackPress(self::TARGET)), 'a press right on the gap');

        $pressThenDrag = Tracks::humanDrag(60);
        $pressThenDrag[0]['pts'] = \array_map(fn(array $p): array => [$p[0], $p[1] + 90, $p[2]], $pressThenDrag[0]['pts']);
        self::assertSame(SliderVerdict::Rejected, self::judge($pressThenDrag), 'a press on the track, then a short drag');

        $jumpAfterDrag = Tracks::humanDrag(20, durationMs: 400);
        $jumpAfterDrag[] = ['k' => 'c', 't' => Tracks::end($jumpAfterDrag) + 500, 'd' => 90.0, 'v' => self::TARGET];
        self::assertSame(SliderVerdict::Rejected, self::judge($jumpAfterDrag), 'a drag, then a press on the gap');
    }

    public function testAPauseDoesNotEarnAJump(): void
    {
        // Hold the handle half a second, jump most of the way, then wiggle onto the target.
        $holdThenJump = [['k' => 'p', 'pt' => 'mouse', 't0' => 600.0, 'co' => 20, 'pts' => [
            [500.0, 140, 0.0], [516.2, 142, 0.4], [533.5, 144, 0.9], [550.1, 145, 1.1], [567.4, 146, 0.8],
            [583.9, 147, 1.3], [601.0, 148, 1.6], [617.8, 149, 1.2], [634.9, 150, 1.5],
        ]]];
        self::assertSame(SliderVerdict::Rejected, self::judge($holdThenJump), 'a hold, then a jump');

        $stopThenJump = Tracks::humanDrag(20);
        $pts = &$stopThenJump[0]['pts'];
        [$dt, , $dy] = $pts[\array_key_last($pts)];
        \array_push($pts, [$dt + 400, 145, $dy + 0.6], [$dt + 417.3, 147, $dy + 1.0], [$dt + 433.9, 149, $dy + 0.7], [$dt + 451.2, 150, $dy + 1.2]);
        unset($pts);
        self::assertSame(SliderVerdict::Rejected, self::judge($stopThenJump), 'a stop in mid-drag, then a jump');

        // A busy page delays one event: the drag goes on meanwhile and arrives as one bigger step.
        foreach ([900, 400] as $duration) {
            $stalled = Tracks::humanDrag(self::TARGET, durationMs: $duration);
            $middle = $duration / 2;
            $stalled[0]['pts'] = \array_values(\array_filter(
                $stalled[0]['pts'],
                static fn(array $p): bool => $p[0] <= $middle - 50 || $p[0] >= $middle + 50,
            ));
            self::assertSame(SliderVerdict::Drag, self::judge($stalled), "a ~100 ms stall in a $duration ms drag");
        }
    }

    public function testScriptedDragsAreRejected(): void
    {
        self::assertSame(SliderVerdict::Rejected, self::judge(Tracks::linearDrag(self::TARGET)), 'linear');

        $wobbly = Tracks::linearDrag(self::TARGET);
        foreach ($wobbly[0]['pts'] as $i => &$sample) {
            $sample[0] += ($i % 3) * 0.4;
            $sample[2] = ($i % 4) * 0.5;
        }
        unset($sample);
        self::assertSame(SliderVerdict::Rejected, self::judge($wobbly), 'linear at a steady speed, with jitter');

        $level = Tracks::humanDrag(self::TARGET);
        $level[0]['pts'] = \array_map(fn(array $p): array => [$p[0], $p[1], 0], $level[0]['pts']);
        self::assertSame(SliderVerdict::Rejected, self::judge($level), 'a mouse held perfectly level');

        $touch = $level;
        $touch[0]['pt'] = 'touch';
        self::assertSame(SliderVerdict::Drag, self::judge($touch), 'touch may report a level line');

        $jump = Tracks::humanDrag(self::TARGET);
        $jump[0]['pts'][5][1] = self::TARGET;
        self::assertSame(SliderVerdict::Rejected, self::judge($jump), 'a jump in the middle of a drag');

        $timer = Tracks::humanDrag(self::TARGET);
        foreach ($timer[0]['pts'] as $i => &$point) {
            $point[0] = $i * 16.0;
        }
        unset($point);
        self::assertSame(SliderVerdict::Rejected, self::judge($timer), 'identical intervals');

        $short = Tracks::humanDrag(self::TARGET, durationMs: 150);
        self::assertSame(SliderVerdict::Rejected, self::judge($short), 'too quick');

        $drift = Tracks::humanDrag(self::TARGET);
        $drift[0]['pts'][3][2] = 200;
        self::assertSame(SliderVerdict::Rejected, self::judge($drift), 'the pointer far off the slider');
    }

    public function testASpeedPeakAtTheEndIsRejected(): void
    {
        $track = Tracks::humanDrag(self::TARGET);
        $pts = &$track[0]['pts'];
        $last = \count($pts) - 1;
        $pts[$last - 1][1] = self::TARGET - 38;
        $pts[$last][0] = $pts[$last - 1][0] + 16.4;

        self::assertSame(SliderVerdict::Rejected, self::judge($track));
    }

    public function testScriptedKeysAreRejected(): void
    {
        $even = [];
        for ($i = 0, $v = 1; $v <= 10; $i++, $v++) {
            $even[] = ['k' => 'k', 't' => 700.0 + $i * 100, 'v' => $v, 'r' => false];
        }
        self::assertSame(SliderVerdict::Rejected, self::judge($even, pos: 10), 'evenly spaced presses');

        $fast = [['k' => 'k', 't' => 700.0, 'v' => 1, 'r' => false], ['k' => 'k', 't' => 710.0, 'v' => 2, 'r' => false]];
        self::assertSame(SliderVerdict::Rejected, self::judge($fast, pos: 2), 'presses 10 ms apart');

        $repeat = Tracks::keyboard(self::TARGET);
        $repeat[1]['t'] = 750.0;
        self::assertSame(SliderVerdict::Rejected, self::judge($repeat), 'auto-repeat without the initial delay');

        $onlyRepeat = \array_slice(Tracks::keyboard(self::TARGET), 1);
        self::assertSame(SliderVerdict::Rejected, self::judge($onlyRepeat), 'auto-repeat without a press');

        $leap = [['k' => 'k', 't' => 700.0, 'v' => self::TARGET, 'r' => false]];
        self::assertSame(SliderVerdict::Rejected, self::judge($leap), 'a key that leaps to the target');

        $tap = Tracks::keyboard(self::TARGET - 3);
        $tap[] = ['k' => 'c', 't' => Tracks::end($tap) + 600, 'd' => 3.0, 'v' => self::TARGET];
        self::assertSame(SliderVerdict::Rejected, self::judge($tap), 'a 3 ms press');
    }

    public function testKeysMayJumpToTheEnds(): void
    {
        $track = [
            ['k' => 'k', 't' => 700.0, 'v' => 236, 'r' => false],
            ['k' => 'k', 't' => 950.0, 'v' => 0, 'r' => false],
            ['k' => 'k', 't' => 1300.0, 'v' => 24, 'r' => false],
        ];

        self::assertSame(SliderVerdict::Assisted, self::judge($track, pos: 24));
    }

    public function testTimingAgainstTheServerClock(): void
    {
        $track = Tracks::humanDrag(self::TARGET);

        self::assertSame(SliderVerdict::Rejected, self::judge($track, elapsedMs: 700), 'answered before anyone could see it');
        self::assertSame(SliderVerdict::Rejected, self::judge($track, elapsedMs: Tracks::end($track) - 500), 'a track longer than the server saw');
        self::assertSame(SliderVerdict::Rejected, self::judge(Tracks::humanDrag(self::TARGET, t0: 50)), 'moved before the picture could be seen');
    }

    public function testTheTrackMustEndAtTheReportedPosition(): void
    {
        self::assertSame(SliderVerdict::Rejected, self::judge(Tracks::humanDrag(self::TARGET), pos: self::TARGET + 2));
    }

    public function testMalformedTracksAreRefused(): void
    {
        $drag = Tracks::humanDrag(self::TARGET);

        foreach ([
            'no list' => ['k' => 'p'],
            'empty' => [],
            'unknown kind' => [['k' => 'x', 't' => 1.0, 'v' => 1]],
            'unknown pointer' => [['pt' => 'robot'] + $drag[0]],
            'value out of range' => [['k' => 'k', 't' => 700.0, 'v' => 300, 'r' => false]],
            'float value' => [['k' => 'k', 't' => 700.0, 'v' => 1.5, 'r' => false]],
            'negative time' => [['k' => 'c', 't' => -1.0, 'd' => 50.0, 'v' => 3]],
            'string repeat' => [['k' => 'k', 't' => 700.0, 'v' => 1, 'r' => 'yes']],
            'time running back' => [['k' => 'k', 't' => 900.0, 'v' => 1, 'r' => false], ['k' => 'k', 't' => 800.0, 'v' => 2, 'r' => false]],
            'overlapping entries' => [...$drag, ['k' => 'k', 't' => 700.0, 'v' => 1, 'r' => false]],
            'short sample' => [['pts' => [[1.0, 2]]] + $drag[0]],
            'too many samples' => \array_fill(0, SliderTrack::MAX_SAMPLES + 1, ['k' => 'k', 't' => 700.0, 'v' => 1, 'r' => false]),
        ] as $case => $data) {
            self::assertNull(SliderTrack::fromArray($data), $case);
        }
    }

    private static function judge(array $track, int $pos = self::TARGET, ?float $elapsedMs = null): SliderVerdict
    {
        $parsed = SliderTrack::fromArray($track);
        self::assertNotNull($parsed, 'the track parses');

        return $parsed->judge($pos, $elapsedMs ?? Tracks::end($track) + 1500);
    }

    /**
     * A short, slightly uneven drag from $from to $to, one sample per pixel.
     */
    private static function nudge(int $from, int $to, float $t0): array
    {
        $pts = [];
        for ($v = $from + 1, $t = 0.0, $i = 0; $v <= $to; $v++, $i++) {
            $t += 17.3 + ($i % 3) * 2.1;
            $pts[] = [\round($t, 1), $v, \round(0.3 * $i, 1)];
        }

        return ['k' => 'p', 'pt' => 'mouse', 't0' => $t0, 'pts' => $pts, 'co' => \count($pts)];
    }

    /**
     * A constant-speed, zero-drift linear drag from $from to $to.
     */
    private static function linearDragFrom(int $from, int $to, float $t0): array
    {
        $pts = [];
        $distance = \abs($to - $from);
        $direction = $to > $from ? 1 : -1;

        for ($v = $from + $direction * 5, $t = 0; ($direction > 0 && $v < $to) || ($direction < 0 && $v > $to); $v += $direction * 5, $t += 10) {
            $pts[] = [(float) $t, $v, 0];
        }
        $pts[] = [(float) $t, $to, 0];

        return ['k' => 'p', 'pt' => 'mouse', 't0' => $t0, 'pts' => $pts, 'co' => \count($pts)];
    }
}
