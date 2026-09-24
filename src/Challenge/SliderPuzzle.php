<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

use GES\Botlock\Image\PngEncoder;

/**
 * The level-3 captcha: a noisy background with a piece-shaped gap and the
 * matching piece, which the visitor slides horizontally into the gap.
 *
 * The target offset only exists in the ticket; the images are the only
 * thing the client gets. The gap is shaded faintly and unevenly with soft
 * edges, among distractor shapes shaded the same way, so it is neither the
 * sharpest nor the only piece-sized outline in the picture. One or two decoy
 * gaps on the piece's row are shaded exactly like the real one but have
 * their notches on other sides. This raises the
 * cost for generic automation, it does not stop a determined attacker with
 * image processing.
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

    /** Width in pixels over which a gap fades in from its outline. */
    private const FEATHER = 2.5;

    /** Minimum horizontal distance between two gaps. */
    private const GAP_SPACING = self::PIECE + 6;

    /** Sides of the piece that carry a round notch, per shape. */
    public const SHAPES = [
        ['left'], ['top'], ['right'], ['bottom'],
        ['left', 'top'], ['left', 'right'], ['left', 'bottom'],
        ['top', 'right'], ['top', 'bottom'], ['right', 'bottom'],
    ];

    /**
     * @param int $target x offset of the gap
     * @param int $pieceY y offset of the piece and all gaps
     * @param int $shape index into SHAPES of the piece and its gap
     * @param list<array{int, int}> $decoys x offset and shape of each decoy gap
     */
    private function __construct(
        public int $target,
        public int $pieceY,
        public int $shape,
        public array $decoys,
        private string $background,
        private string $piece,
    ) {}

    public static function create(): self
    {
        $target = \random_int(self::MIN_TARGET, self::WIDTH - self::PIECE - self::MARGIN);
        $pieceY = \random_int(self::MARGIN, self::HEIGHT - self::PIECE - self::MARGIN);
        $shape = \random_int(0, \count(self::SHAPES) - 1);
        $decoys = self::decoys($target, $shape);

        $waves = self::waves();
        $pixels = self::background();
        self::distractors($pixels, $waves, $pieceY, [$target, ...\array_column($decoys, 0)]);
        $piece = self::cut($pixels, $target, $pieceY, $shape);

        $depth = \random_int(22, 36) / 100;
        self::carve($pixels, $target, $pieceY, $shape, $depth, $waves);
        foreach ($decoys as [$x, $decoyShape]) {
            self::carve($pixels, $x, $pieceY, $decoyShape, $depth, $waves);
        }
        self::overlay($pixels);

        return new self(
            $target,
            $pieceY,
            $shape,
            $decoys,
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
     * One or two decoy gaps on the piece's row, spaced apart from the gap
     * and each other, whose notches differ from the piece's and each
     * other's on at least two sides.
     *
     * @return list<array{int, int}>
     */
    private static function decoys(int $target, int $shape): array
    {
        $taken = [[$target, $shape]];
        $wanted = \random_int(1, 2);

        for ($attempt = 0; $attempt < 50 && \count($taken) <= $wanted; $attempt++) {
            $x = \random_int(self::PIECE + self::MARGIN, self::WIDTH - self::PIECE - self::MARGIN);
            $candidate = \random_int(0, \count(self::SHAPES) - 1);

            foreach ($taken as [$otherX, $otherShape]) {
                if (\abs($x - $otherX) < self::GAP_SPACING || !self::isDistinct($candidate, $otherShape)) {
                    continue 2;
                }
            }

            $taken[] = [$x, $candidate];
        }

        return \array_slice($taken, 1);
    }

    /**
     * Whether two shapes differ in at least two notches.
     */
    public static function isDistinct(int $a, int $b): bool
    {
        $difference = \array_merge(\array_diff(self::SHAPES[$a], self::SHAPES[$b]), \array_diff(self::SHAPES[$b], self::SHAPES[$a]));

        return \count($difference) >= 2;
    }

    /**
     * Random two-colour gradient with translucent blobs.
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

                $pixels .= \chr((int) $rgb[0]) . \chr((int) $rgb[1]) . \chr((int) $rgb[2]);
            }
        }

        return $pixels;
    }

    /**
     * The piece: the pixels under the gap with fresh noise and an
     * antialiased outline, transparent outside its shape.
     */
    private static function cut(string $pixels, int $left, int $top, int $shape): string
    {
        $piece = '';

        for ($y = 0; $y < self::PIECE; $y++) {
            for ($x = 0; $x < self::PIECE; $x++) {
                $alpha = \min(1.0, \max(0.0, 0.5 - self::distance($shape, $x, $y)));

                if ($alpha === 0.0) {
                    $piece .= "\0\0\0\0";
                    continue;
                }

                $offset = (($top + $y) * self::WIDTH + $left + $x) * 3;
                $piece .= \chr(self::jitter(\ord($pixels[$offset]), 6))
                    . \chr(self::jitter(\ord($pixels[$offset + 1]), 6))
                    . \chr(self::jitter(\ord($pixels[$offset + 2]), 6))
                    . \chr((int) \round($alpha * 255));
            }
        }

        return $piece;
    }

    /**
     * Darkens a piece-shaped gap: faintly, fading in from the outline and
     * modulated by the waves, so it has neither a rim nor a flat inside.
     *
     * @param list<array{float, float, float, float}> $waves
     */
    private static function carve(string &$pixels, int $left, int $top, int $shape, float $depth, array $waves): void
    {
        for ($y = 0; $y < self::PIECE; $y++) {
            for ($x = 0; $x < self::PIECE; $x++) {
                $t = \min(1.0, \max(0.0, (0.5 - self::distance($shape, $x, $y)) / (self::FEATHER + 0.5)));

                if ($t === 0.0) {
                    continue;
                }

                self::shade($pixels, $left + $x, $top + $y, $depth * $t * $t * (3 - 2 * $t), $waves);
            }
        }
    }

    /**
     * Rounded rectangles, circles and half rings, darkened or lightened
     * like a gap, so the gap's outline has company of the same contrast.
     * A few sit on the piece's row with about its size, where a solver
     * that knows the row looks, but clear of the gaps so their outlines
     * stay readable.
     *
     * @param list<array{float, float, float, float}> $waves
     * @param list<int> $gaps x offsets of the gap and the decoys
     */
    private static function distractors(string &$pixels, array $waves, int $pieceY, array $gaps): void
    {
        $inRow = \random_int(2, 3);
        $half = \intdiv(self::PIECE, 2);

        for ($i = \random_int(10, 14); $i > 0; $i--) {
            $cx = \random_int(0, self::WIDTH);

            if ($i <= $inRow) {
                $width = \random_int($half - 6, $half + 2);
                $cx = self::clearOf($gaps, $width);
                if ($cx === null) {
                    continue;
                }
                $cy = $pieceY + $half + \random_int(-6, 6);
                $shape = ['rect', $cx, $cy, $width, \random_int($half - 6, $half + 2), \random_int(6, 12)];
            } else {
                $cy = \random_int(0, self::HEIGHT);
                $shape = null;
            }

            $shape ??= match (\random_int(0, 2)) {
                0 => ['rect', $cx, $cy, \random_int(12, 30), \random_int(12, 30), \random_int(4, 14)],
                1 => ['circle', $cx, $cy, \random_int(12, 30)],
                2 => ['arc', $cx, $cy, \random_int(12, 30), \random_int(3, 6), \random_int(0, 359) / 180 * \M_PI],
            };
            $reach = (int) \ceil(\max($shape[3], $shape[4] ?? 0) + self::FEATHER + 1);
            $depth = \random_int(22, 36) / 100 * (\random_int(0, 1) === 1 ? 1 : -1);

            for ($y = \max(0, $cy - $reach); $y < \min(self::HEIGHT, $cy + $reach); $y++) {
                for ($x = \max(0, $cx - $reach); $x < \min(self::WIDTH, $cx + $reach); $x++) {
                    $t = \min(1.0, \max(0.0, (0.5 - self::shapeDistance($shape, $x, $y)) / (self::FEATHER + 0.5)));
                    if ($t > 0.0) {
                        self::shade($pixels, $x, $y, $depth * $t * $t * (3 - 2 * $t), $waves);
                    }
                }
            }
        }
    }

    /**
     * A random centre for a shape of the given half width that keeps clear
     * of the gaps, or null if none turns up.
     *
     * @param list<int> $gaps
     */
    private static function clearOf(array $gaps, int $halfWidth): ?int
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $cx = \random_int(0, self::WIDTH);

            foreach ($gaps as $x) {
                if (\abs($cx - $x - \intdiv(self::PIECE, 2)) < \intdiv(self::PIECE, 2) + $halfWidth + 2) {
                    continue 2;
                }
            }

            return $cx;
        }

        return null;
    }

    /**
     * Darkens a pixel by the given amount, or lightens it for a negative
     * one, modulated by the waves.
     *
     * @param list<array{float, float, float, float}> $waves
     */
    private static function shade(string &$pixels, int $x, int $y, float $amount, array $waves): void
    {
        $amount *= self::modulation($waves, $x, $y);
        $offset = ($y * self::WIDTH + $x) * 3;

        for ($c = 0; $c < 3; $c++) {
            $value = \ord($pixels[$offset + $c]);
            $value = $amount > 0 ? $value * (1 - $amount) : $value - (255 - $value) * $amount;
            $pixels[$offset + $c] = \chr(\min(255, (int) $value));
        }
    }

    /**
     * Soft translucent veils across everything, including gap outlines,
     * then per-pixel noise.
     */
    private static function overlay(string &$pixels): void
    {
        $veils = [];
        for ($i = \random_int(4, 6); $i > 0; $i--) {
            $veils[] = [
                \random_int(0, self::WIDTH), \random_int(0, self::HEIGHT), \random_int(15, 45),
                [\random_int(0, 255), \random_int(0, 255), \random_int(0, 255)],
            ];
        }

        for ($y = 0; $y < self::HEIGHT; $y++) {
            for ($x = 0; $x < self::WIDTH; $x++) {
                $offset = ($y * self::WIDTH + $x) * 3;
                $rgb = [\ord($pixels[$offset]), \ord($pixels[$offset + 1]), \ord($pixels[$offset + 2])];

                foreach ($veils as [$cx, $cy, $radius, $color]) {
                    $fade = ($radius - \sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2)) / 8;
                    if ($fade > 0) {
                        $alpha = 0.25 * \min(1.0, $fade);
                        for ($c = 0; $c < 3; $c++) {
                            $rgb[$c] += ($color[$c] - $rgb[$c]) * $alpha;
                        }
                    }
                }

                for ($c = 0; $c < 3; $c++) {
                    $pixels[$offset + $c] = \chr(self::jitter((int) $rgb[$c], 8));
                }
            }
        }
    }

    /**
     * A few random plane waves that make a gap's shading uneven.
     *
     * @return list<array{float, float, float, float}> x and y frequency, phase and weight
     */
    private static function waves(): array
    {
        $waves = [];
        for ($i = 0; $i < 3; $i++) {
            $angle = \random_int(0, 359) / 180 * \M_PI;
            $frequency = 2 * \M_PI / \random_int(20, 60);
            $waves[] = [\cos($angle) * $frequency, \sin($angle) * $frequency, \random_int(0, 359) / 180 * \M_PI, \random_int(5, 10) / 10];
        }

        return $waves;
    }

    /**
     * @param list<array{float, float, float, float}> $waves
     * @return float between 0.75 and 1.25
     */
    private static function modulation(array $waves, int $x, int $y): float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($waves as [$fx, $fy, $phase, $weight]) {
            $sum += $weight * \sin($fx * $x + $fy * $y + $phase);
            $weights += $weight;
        }

        return 1 + 0.25 * $sum / $weights;
    }

    /**
     * Signed distance from a point in the piece's box to its outline,
     * negative inside: a rounded square with round notches cut into the
     * sides the shape names.
     */
    private static function distance(int $shape, float $x, float $y): float
    {
        $size = self::PIECE - 1;
        $half = $size / 2;

        $qx = \abs($x - $half) - ($half - self::CORNER);
        $qy = \abs($y - $half) - ($half - self::CORNER);
        $distance = \sqrt(\max($qx, 0) ** 2 + \max($qy, 0) ** 2) + \min(\max($qx, $qy), 0) - self::CORNER;

        foreach (self::SHAPES[$shape] as $side) {
            [$nx, $ny] = match ($side) {
                'left' => [0, $half],
                'top' => [$half, 0],
                'right' => [$size, $half],
                'bottom' => [$half, $size],
            };
            $distance = \max($distance, self::NOTCH - \sqrt(($x - $nx) ** 2 + ($y - $ny) ** 2));
        }

        return $distance;
    }

    /**
     * Signed distance to a distractor outline, negative inside.
     */
    private static function shapeDistance(array $shape, int $x, int $y): float
    {
        [$kind, $cx, $cy] = $shape;
        $dx = $x - $cx;
        $dy = $y - $cy;

        return match ($kind) {
            'rect' => (static function () use ($shape, $dx, $dy): float {
                [, , , $halfWidth, $halfHeight, $corner] = $shape;
                $qx = \abs($dx) - $halfWidth + $corner;
                $qy = \abs($dy) - $halfHeight + $corner;

                return \sqrt(\max($qx, 0) ** 2 + \max($qy, 0) ** 2) + \min(\max($qx, $qy), 0) - $corner;
            })(),
            'circle' => \sqrt($dx ** 2 + $dy ** 2) - $shape[3],
            'arc' => \max(
                \abs(\sqrt($dx ** 2 + $dy ** 2) - $shape[3]) - $shape[4] / 2,
                $dx * \cos($shape[5]) + $dy * \sin($shape[5]),
            ),
        };
    }

    private static function jitter(int $value, int $amount): int
    {
        return \min(255, \max(0, $value + \random_int(-$amount, $amount)));
    }
}
