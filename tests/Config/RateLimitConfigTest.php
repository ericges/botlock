<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Config;

use GES\Botlock\Config\RateLimitConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimitConfigTest extends TestCase
{
    private const NAMES = [
        'THRESHOLD_FACTOR',
        'LEVEL_1_THRESHOLD_GLOBAL',
        'LEVEL_2_THRESHOLD_GLOBAL',
        'LEVEL_3_THRESHOLD_GLOBAL',
        'LEVEL_1_THRESHOLD_INDIVIDUAL',
        'LEVEL_2_THRESHOLD_INDIVIDUAL',
        'LEVEL_3_THRESHOLD_INDIVIDUAL',
        'LEVEL_4_THRESHOLD_INDIVIDUAL',
        'SLIDER_IP_LIMIT',
        'SLIDER_IP_WINDOW_SEC',
    ];

    protected function tearDown(): void
    {
        foreach (self::NAMES as $name) {
            \putenv('BOTLOCK_' . $name);
        }
    }

    public function testWithoutFactorThresholdsKeepTheirDefaults(): void
    {
        self::assertSame([120, 300, 600, 60, 90, 120, 180], self::thresholds(RateLimitConfig::fromEnv()));
    }

    public function testFactorScalesDefaultThresholds(): void
    {
        \putenv('BOTLOCK_THRESHOLD_FACTOR=2.5');

        self::assertSame([300, 750, 1500, 150, 225, 300, 450], self::thresholds(RateLimitConfig::fromEnv()));
    }

    public function testFactorScalesExplicitThresholdsAndRounds(): void
    {
        \putenv('BOTLOCK_THRESHOLD_FACTOR=0.5');
        \putenv('BOTLOCK_LEVEL_1_THRESHOLD_INDIVIDUAL=10');
        \putenv('BOTLOCK_LEVEL_2_THRESHOLD_INDIVIDUAL=45');
        \putenv('BOTLOCK_LEVEL_4_THRESHOLD_INDIVIDUAL=40');

        self::assertSame([60, 150, 300, 5, 23, 60, 20], self::thresholds(RateLimitConfig::fromEnv()));
    }

    public function testTinyFactorKeepsPositiveThresholdsAboveZero(): void
    {
        \putenv('BOTLOCK_THRESHOLD_FACTOR=0.001');

        $config = RateLimitConfig::fromEnv();

        self::assertSame([1, 1, 1, 1, 1, 1, 1], self::thresholds($config));
        self::assertFalse($config->isLevel4Enabled(), 'level 4 equal to level 3 stays off');
    }

    public function testDisabledLevel4StaysDisabled(): void
    {
        \putenv('BOTLOCK_THRESHOLD_FACTOR=2');
        \putenv('BOTLOCK_LEVEL_4_THRESHOLD_INDIVIDUAL=0');

        $config = RateLimitConfig::fromEnv();

        self::assertSame(0, $config->level4ThresholdIndividual);
        self::assertFalse($config->isLevel4Enabled());
    }

    public function testSliderBudgetDefaultsAndClamping(): void
    {
        $config = RateLimitConfig::fromEnv();
        self::assertSame([10, 600], [$config->sliderIpLimit, $config->sliderIpWindowSec]);

        \putenv('BOTLOCK_THRESHOLD_FACTOR=3');
        \putenv('BOTLOCK_SLIDER_IP_LIMIT=-5');
        \putenv('BOTLOCK_SLIDER_IP_WINDOW_SEC=0');
        $config = RateLimitConfig::fromEnv();
        self::assertSame([0, 1], [$config->sliderIpLimit, $config->sliderIpWindowSec], 'clamped, and not scaled by the factor');

        \putenv('BOTLOCK_SLIDER_IP_LIMIT=25');
        self::assertSame(25, RateLimitConfig::fromEnv()->sliderIpLimit);
    }

    #[DataProvider('invalidFactors')]
    public function testInvalidFactorFallsBackToOne(string $raw): void
    {
        \putenv('BOTLOCK_THRESHOLD_FACTOR=' . $raw);

        self::assertSame([120, 300, 600, 60, 90, 120, 180], self::thresholds(RateLimitConfig::fromEnv()));
    }

    public static function invalidFactors(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['abc'];
        yield 'infinity' => ['INF'];
        yield 'overflow' => ['1e400'];
        yield 'empty' => [''];
    }

    /** @return list<int> global levels 1–3, then individual levels 1–4 */
    private static function thresholds(RateLimitConfig $config): array
    {
        return [
            $config->level1ThresholdGlobal,
            $config->level2ThresholdGlobal,
            $config->level3ThresholdGlobal,
            $config->level1ThresholdIndividual,
            $config->level2ThresholdIndividual,
            $config->level3ThresholdIndividual,
            $config->level4ThresholdIndividual,
        ];
    }
}
