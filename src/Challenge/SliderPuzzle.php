<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

use GES\Botlock\Image\PngEncoder;

/**
 * The level-3 captcha: a noisy background with a piece-shaped gap and the
 * matching piece, which the visitor slides horizontally into the gap.
 *
 * The target offset only exists in the ticket; the images are the only
 * thing the client gets. This raises the cost for generic automation, it
 * does not stop a determined attacker with image processing.
 */
final readonly class SliderPuzzle
{
    public const WIDTH = 280;
    public const HEIGHT = 160;
    public const PIECE = 44;

    /** Pixels a submitted offset may be off by. */
    public const TOLERANCE = 5;

    private const MIN_TARGET = 70;
    private const MARGIN = 8;
    private const CORNER = 9;
    private const NOTCH = 7;

    /**
     * @param int $target x offset of the gap
     * @param int $pieceY y offset of the piece and the gap
     */
    private function __construct(
        public int $target,
        public int $pieceY,
        private string $background,
        private string $piece,
    ) {}

    public static function create(): self
    {
        $target = \random_int(self::MIN_TARGET, self::WIDTH - self::PIECE - self::MARGIN);
        $pieceY = \random_int(self::MARGIN, self::HEIGHT - self::PIECE - self::MARGIN);

        $pixels = self::background();
        $piece = '';

        for ($y = 0; $y < self::PIECE; $y++) {
            for ($x = 0; $x < self::PIECE; $x++) {
                $offset = (($pieceY + $y) * self::WIDTH + $target + $x) * 3;
                $inside = self::inside($x, $y);

                if (!$inside) {
                    $piece .= "\0\0\0\0";
                    continue;
                }

                [$r, $g, $b] = [\ord($pixels[$offset]), \ord($pixels[$offset + 1]), \ord($pixels[$offset + 2])];
                $edge = self::isEdge($x, $y);

                // The piece keeps the original pixels with fresh noise and a light rim …
                $piece .= $edge
                    ? "\xF5\xF5\xF5\xFF"
                    : \chr(self::jitter($r, 6)) . \chr(self::jitter($g, 6)) . \chr(self::jitter($b, 6)) . "\xFF";

                // … the gap is darkened, with a faint rim of its own.
                $shade = $edge ? 0.75 : 0.45;
                $pixels[$offset] = \chr((int) ($r * $shade));
                $pixels[$offset + 1] = \chr((int) ($g * $shade));
                $pixels[$offset + 2] = \chr((int) ($b * $shade));
            }
        }

        return new self(
            $target,
            $pieceY,
            PngEncoder::encode(self::WIDTH, self::HEIGHT, $pixels),
            PngEncoder::encode(self::PIECE, self::PIECE, $piece, true),
        );
    }

    public static function accepts(mixed $position, int $target): bool
    {
        if (\is_string($position) && \ctype_digit($position)) {
            $position = (int) $position;
        }

        return \is_int($position) && \abs($position - $target) <= self::TOLERANCE;
    }

    /**
     * What the client gets: images and geometry, never the target.
     */
    public function toArray(): array
    {
        return [
            'bg' => PngEncoder::dataUri($this->background),
            'piece' => PngEncoder::dataUri($this->piece),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'size' => self::PIECE,
            'y' => $this->pieceY,
        ];
    }

    /**
     * Random two-colour gradient with translucent blobs and per-pixel noise.
     */
    private static function background(): string
    {
        $from = [\random_int(40, 200), \random_int(40, 200), \random_int(40, 200)];
        $to = [\random_int(40, 200), \random_int(40, 200), \random_int(40, 200)];

        $blobs = [];
        for ($i = 0; $i < 16; $i++) {
            $blobs[] = [
                \random_int(0, self::WIDTH), \random_int(0, self::HEIGHT), \random_int(8, 38) ** 2,
                [\random_int(0, 255), \random_int(0, 255), \random_int(0, 255)],
            ];
        }

        $pixels = '';
        for ($y = 0; $y < self::HEIGHT; $y++) {
            for ($x = 0; $x < self::WIDTH; $x++) {
                $t = ($x + $y) / (self::WIDTH + self::HEIGHT);
                $rgb = [
                    $from[0] + ($to[0] - $from[0]) * $t,
                    $from[1] + ($to[1] - $from[1]) * $t,
                    $from[2] + ($to[2] - $from[2]) * $t,
                ];

                foreach ($blobs as [$cx, $cy, $r2, $color]) {
                    if (($x - $cx) ** 2 + ($y - $cy) ** 2 <= $r2) {
                        $rgb = [($rgb[0] + $color[0]) / 2, ($rgb[1] + $color[1]) / 2, ($rgb[2] + $color[2]) / 2];
                    }
                }

                $pixels .= \chr(self::jitter((int) $rgb[0], 8)) . \chr(self::jitter((int) $rgb[1], 8)) . \chr(self::jitter((int) $rgb[2], 8));
            }
        }

        return $pixels;
    }

    /**
     * Piece shape: a rounded square with a round notch in its left side.
     */
    private static function inside(int $x, int $y): bool
    {
        $size = self::PIECE - 1;
        $r = self::CORNER;

        $cx = \min(\max($x, $r), $size - $r);
        $cy = \min(\max($y, $r), $size - $r);

        if (($x - $cx) ** 2 + ($y - $cy) ** 2 > $r * $r) {
            return false;
        }

        return $x ** 2 + ($y - $size / 2) ** 2 > self::NOTCH ** 2;
    }

    private static function isEdge(int $x, int $y): bool
    {
        return !self::inside($x - 1, $y) || !self::inside($x + 1, $y)
            || !self::inside($x, $y - 1) || !self::inside($x, $y + 1);
    }

    private static function jitter(int $value, int $amount): int
    {
        return \min(255, \max(0, $value + \random_int(-$amount, $amount)));
    }
}
