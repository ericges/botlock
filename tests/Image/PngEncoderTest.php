<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Image;

use GES\Botlock\Image\PngEncoder;
use PHPUnit\Framework\TestCase;

final class PngEncoderTest extends TestCase
{
    public function testWritesSignatureAndHeader(): void
    {
        $png = PngEncoder::encode(3, 2, \str_repeat("\x10\x20\x30", 6));

        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        self::assertSame(['w' => 3, 'h' => 2, 'depth' => 8, 'type' => 2], \unpack('Nw/Nh/Cdepth/Ctype', \substr($png, 16, 10)));
        self::assertStringEndsWith("IEND\xAE\x42\x60\x82", $png);
    }

    public function testRgbaSetsColourTypeSix(): void
    {
        $png = PngEncoder::encode(1, 1, "\x01\x02\x03\x04", alpha: true);

        self::assertSame(6, \ord($png[25]));
    }

    public function testImageDecodesToTheSamePixels(): void
    {
        if (!\function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD is not available');
        }

        $image = \imagecreatefromstring(PngEncoder::encode(2, 1, "\xFF\x00\x00\x00\x00\xFF"));

        self::assertSame(0xFF0000, \imagecolorat($image, 0, 0));
        self::assertSame(0x0000FF, \imagecolorat($image, 1, 0));
    }

    public function testRejectsMismatchedPixelData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PngEncoder::encode(2, 2, "\x00\x00\x00");
    }
}
