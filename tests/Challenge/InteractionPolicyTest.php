<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\Interaction;
use GES\Botlock\Challenge\InteractionPolicy;
use GES\Botlock\Config\ProofOfWorkConfig;
use PHPUnit\Framework\TestCase;

final class InteractionPolicyTest extends TestCase
{
    public function testBrowsersClickFromLevelTwo(): void
    {
        $policy = self::policy();

        self::assertSame(Interaction::None, $policy->interaction(1, false, false));
        self::assertSame(Interaction::Click, $policy->interaction(2, false, false));
    }

    public function testUntrustedCrawlersAlwaysInteract(): void
    {
        self::assertSame(Interaction::Click, self::policy()->interaction(1, true, false));
        self::assertSame(Interaction::Click, self::policy()->interaction(2, true, false));
    }

    public function testTrustedGoodBotsAreExemptFromTheEscalation(): void
    {
        foreach ([1, 2, 3] as $level) {
            self::assertSame(Interaction::None, self::policy()->interaction($level, true, true), "level $level");
        }
    }

    public function testDifficulty(): void
    {
        $policy = self::policy(crawlerFactor: 15);

        self::assertSame(1.0, $policy->difficulty(false, false));
        self::assertSame(15.0, $policy->difficulty(true, false));
        self::assertSame(0.5, $policy->difficulty(true, true));
    }

    private static function policy(int $crawlerFactor = 15): InteractionPolicy
    {
        return new InteractionPolicy(new ProofOfWorkConfig(secret: 's', crawlerFactor: $crawlerFactor));
    }
}
