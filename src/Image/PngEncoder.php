<?php declare(strict_types=1);

namespace GES\Botlock\Image;

/**
 * Minimal PNG writer for 8-bit truecolour images, so the slider puzzle
 * needs no image extension. Uses zlib when it is available and falls back
 * to uncompressed deflate blocks otherwise.
 */
final class PngEncoder
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const STORED_BLOCK_MAX = 0xFFFF;

    /**
     * @param string $pixels row-major RGB (3 bytes per pixel) or RGBA (4 bytes) data
     */
    public static function encode(int $width, int $height, string $pixels, bool $alpha = false): string
    {
        $channels = $alpha ? 4 : 3;
        $stride = $width * $channels;

        if ($width < 1 || $height < 1 || \strlen($pixels) !== $stride * $height) {
            throw new \InvalidArgumentException('Pixel data does not match the image size');
        }

        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0" . \substr($pixels, $y * $stride, $stride); // filter type 0: none
        }

        return self::SIGNATURE
            . self::chunk('IHDR', \pack('NNCCCCC', $width, $height, 8, $alpha ? 6 : 2, 0, 0, 0))
            . self::chunk('IDAT', self::zlib($raw))
            . self::chunk('IEND', '');
    }

    public static function dataUri(string $png): string
    {
        return 'data:image/png;base64,' . \base64_encode($png);
    }

    private static function chunk(string $type, string $data): string
    {
        return \pack('N', \strlen($data)) . $type . $data . \pack('N', \crc32($type . $data));
    }

    private static function zlib(string $data): string
    {
        if (\function_exists('gzcompress')) {
            return \gzcompress($data, 6);
        }

        $out = "\x78\x01";
        $blocks = \str_split($data, self::STORED_BLOCK_MAX);
        $last = \count($blocks) - 1;

        foreach ($blocks as $index => $block) {
            $length = \strlen($block);
            $out .= \chr($index === $last ? 1 : 0) . \pack('vv', $length, ~$length & 0xFFFF) . $block;
        }

        return $out . \hex2bin(\hash('adler32', $data));
    }
}
