<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Config;

use GES\Botlock\Config\ProofOfWorkConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProofOfWorkConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        \putenv('BOTLOCK_SLIDER_ASSISTED_FACTOR');
    }

    public static function assistedFactors(): array
    {
        return [
            'unset' => [null, 4],
            'zero is clamped, not replaced' => ['0', 1],
            'negative' => ['-3', 1],
            'set' => ['7', 7],
        ];
    }

    #[DataProvider('assistedFactors')]
    public function testAssistedFactorFromEnv(?string $value, int $expected): void
    {
        if ($value !== null) {
            \putenv('BOTLOCK_SLIDER_ASSISTED_FACTOR=' . $value);
        }

        self::assertSame($expected, ProofOfWorkConfig::fromEnv('secret')->getAssistedFactor());
    }
}
