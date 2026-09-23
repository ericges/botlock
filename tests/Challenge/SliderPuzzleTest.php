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
