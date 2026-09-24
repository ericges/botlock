<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\SliderPuzzle;
use PHPUnit\Framework\TestCase;

final class SliderPuzzleTest extends TestCase
{
    public function testTargetLeavesRoomForThePiece(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $puzzle = SliderPuzzle::create();

            self::assertGreaterThanOrEqual(70, $puzzle->target);
            self::assertLessThanOrEqual(SliderPuzzle::WIDTH - SliderPuzzle::PIECE, $puzzle->target);
            self::assertGreaterThanOrEqual(0, $puzzle->pieceY);
            self::assertLessThanOrEqual(SliderPuzzle::HEIGHT - SliderPuzzle::PIECE, $puzzle->pieceY);
        }
    }

    public function testDecoysShareTheRowButNotTheSpotOrTheShape(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $puzzle = SliderPuzzle::create();

            self::assertContains(\count($puzzle->decoys), [1, 2]);
            $gaps = [[$puzzle->target, $puzzle->shape], ...$puzzle->decoys];

            foreach ($puzzle->decoys as [$x]) {
                self::assertGreaterThanOrEqual(SliderPuzzle::PIECE, $x);
                self::assertLessThanOrEqual(SliderPuzzle::WIDTH - SliderPuzzle::PIECE, $x);
            }

            foreach ($gaps as $a => [$x, $shape]) {
                foreach (\array_slice($gaps, $a + 1) as [$otherX, $otherShape]) {
                    self::assertGreaterThanOrEqual(SliderPuzzle::PIECE + 6, \abs($x - $otherX), 'gaps do not overlap');
                    self::assertTrue(SliderPuzzle::isDistinct($shape, $otherShape), 'gaps differ in at least two notches');
                }
            }
        }
    }

    public function testPieceShapeVaries(): void
    {
        $shapes = [];
        for ($i = 0; $i < 50; $i++) {
            $shapes[SliderPuzzle::create()->shape] = true;
        }

        self::assertGreaterThan(1, \count($shapes));
    }

    public function testDistinctShapesDifferInTwoNotches(): void
    {
        $shapes = \array_flip(\array_map(fn(array $sides): string => \implode(',', $sides), SliderPuzzle::SHAPES));

        self::assertTrue(SliderPuzzle::isDistinct($shapes['left'], $shapes['top']));
        self::assertTrue(SliderPuzzle::isDistinct($shapes['left'], $shapes['top,right']));
        self::assertFalse(SliderPuzzle::isDistinct($shapes['left'], $shapes['left,top']));
        self::assertFalse(SliderPuzzle::isDistinct($shapes['left'], $shapes['left']));
    }

    public function testClientDataHoldsImagesButNoTarget(): void
    {
        $data = SliderPuzzle::create()->toArray();

        self::assertSame(['bg', 'piece', 'width', 'height', 'size', 'y'], \array_keys($data));
        self::assertStringStartsWith('data:image/png;base64,', $data['bg']);
        self::assertStringStartsWith('data:image/png;base64,', $data['piece']);

        if (\function_exists('getimagesizefromstring')) {
            self::assertSame([SliderPuzzle::WIDTH, SliderPuzzle::HEIGHT], \array_slice(\getimagesizefromstring(\base64_decode(\substr($data['bg'], 22))), 0, 2));
            self::assertSame([SliderPuzzle::PIECE, SliderPuzzle::PIECE], \array_slice(\getimagesizefromstring(\base64_decode(\substr($data['piece'], 22))), 0, 2));
        }
    }

    public function testAcceptsOffsetsWithinTheTolerance(): void
    {
        self::assertTrue(SliderPuzzle::accepts(100, 100));
        self::assertTrue(SliderPuzzle::accepts(100 + SliderPuzzle::TOLERANCE, 100));
        self::assertTrue(SliderPuzzle::accepts('97', 100));
        self::assertFalse(SliderPuzzle::accepts(100 + SliderPuzzle::TOLERANCE + 1, 100));
        self::assertFalse(SliderPuzzle::accepts(null, 100));
        self::assertFalse(SliderPuzzle::accepts('100.0', 100));
        self::assertFalse(SliderPuzzle::accepts([100], 100));
    }
}
