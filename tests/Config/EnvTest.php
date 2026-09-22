<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Config;

use GES\Botlock\Config\Env;
use GES\Botlock\Config\RateLimitConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private const NAME = 'UNIT_TEST_VALUE';

    protected function tearDown(): void
    {
        \putenv('BOTLOCK_' . self::NAME);
    }

    public function testUnsetYieldsDefault(): void
    {
        self::assertSame('dflt', Env::get(self::NAME, 'dflt'));
        self::assertNull(Env::bool(self::NAME));
        self::assertTrue(Env::bool(self::NAME, true));
        self::assertNull(Env::list(self::NAME));
        self::assertSame(7, Env::int(self::NAME, 7));
    }

    public function testValuesAreTrimmedAndNameIsUppercased(): void
    {
        \putenv('BOTLOCK_' . self::NAME . '=  spaced  ');

        self::assertSame('spaced', Env::get(\strtolower(self::NAME)));
    }

    #[DataProvider('booleans')]
    public function testBoolAcceptsDocumentedSpellings(string $raw, ?bool $expected): void
    {
        \putenv('BOTLOCK_' . self::NAME . '=' . $raw);

        self::assertSame($expected, Env::bool(self::NAME));
    }

    public static function booleans(): iterable
    {
        foreach (['1', 'true', 'TRUE', 'on', 'yes', 'Yes'] as $v) yield "truthy $v" => [$v, true];
        foreach (['0', 'false', 'off', 'no', 'NO'] as $v) yield "falsy $v" => [$v, false];
        yield 'empty falls back to default' => ['', null];
        yield 'garbage falls back to default' => ['maybe', null];
    }

    public function testListParsesCsvAndJson(): void
    {
        \putenv('BOTLOCK_' . self::NAME . '=a, b,c');
        self::assertSame(['a', ' b', 'c'], Env::list(self::NAME));

        \putenv('BOTLOCK_' . self::NAME . '=["x", "y", 3, null]');
        self::assertSame(['x', 'y'], Env::list(self::NAME), 'non-strings are dropped');

        \putenv('BOTLOCK_' . self::NAME . '=');
        self::assertSame([], Env::list(self::NAME), 'explicitly empty differs from unset');

        \putenv('BOTLOCK_' . self::NAME . '=[not json]');
        self::assertSame([], Env::list(self::NAME), 'bracketed but invalid JSON yields an empty list');

        \putenv('BOTLOCK_' . self::NAME . '=[not-a-list');
        self::assertSame(['[not-a-list'], Env::list(self::NAME), 'without a closing bracket it is plain CSV');
    }

    public function testIntFallsBackForNonNumeric(): void
    {
        \putenv('BOTLOCK_' . self::NAME . '=42');
        self::assertSame(42, Env::int(self::NAME, 1));

        \putenv('BOTLOCK_' . self::NAME . '=lots');
        self::assertSame(1, Env::int(self::NAME, 1));
    }

    public function testRateLimitMasterSwitchNeedsAtLeastOneLimit(): void
    {
        self::assertTrue((new RateLimitConfig())->isRateLimitEnabled());
        self::assertFalse((new RateLimitConfig(enableRateLimit: false))->isRateLimitEnabled());
        self::assertFalse((new RateLimitConfig(enableGlobalRateLimit: false, enableIndividualRateLimit: false))->isRateLimitEnabled());
        self::assertTrue((new RateLimitConfig(enableGlobalRateLimit: false))->isRateLimitEnabled());
    }
}
