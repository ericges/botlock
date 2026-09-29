<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Filesystem;

use GES\Botlock\Filesystem\Sweep;
use PHPUnit\Framework\TestCase;

final class SweepTest extends TestCase
{
    public function testZeroOrLessNeverSweeps(): void
    {
        for ($i = 0; $i < 50; $i++) {
            self::assertFalse(Sweep::isDue(0));
            self::assertFalse(Sweep::isDue(-5));
        }
    }

    public function testOneSweepsEveryTime(): void
    {
        for ($i = 0; $i < 50; $i++) {
            self::assertTrue(Sweep::isDue(1));
        }
    }
}
