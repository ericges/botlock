<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Http;

use GES\Botlock\Http\RequestContext;
use GES\Botlock\Http\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestContextTest extends TestCase
{
    public static function requiredLevels(): array
    {
        return [
            'not evaluated' => [null, 1],
            'level 0' => [0, 1],
            'level 2' => [2, 2],
            'level 4 stays uncapped' => [4, 4],
        ];
    }

    #[DataProvider('requiredLevels')]
    public function testRequiredLevelIsAtLeastOne(?int $threatLevel, int $expected): void
    {
        $context = new RequestContext();
        $context->threatLevel = $threatLevel;

        self::assertSame($expected, $context->requiredLevel());
    }

    public function testGrantMustCoverTheRequiredLevel(): void
    {
        $context = new RequestContext();
        $context->session = new Session(secret: 's', ttl: 300, sub: 'fp', origin: 'https://example.test', host: 'example.test', secure: true);
        $context->session->set('grant', 3);

        $context->threatLevel = 0;
        self::assertTrue($context->isGrantSufficient());

        $context->threatLevel = 3;
        self::assertTrue($context->isGrantSufficient());

        $context->threatLevel = 4;
        self::assertFalse($context->isGrantSufficient(), 'no grant covers level 4');
    }
}
