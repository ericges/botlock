<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Crawler\CrawlerVerification;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Middleware\ThreatBlockMiddleware;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ThreatBlockMiddlewareTest extends TestCase
{
    public function testLevelsBelowFourContinue(): void
    {
        foreach ([null, 0, 1, 2, 3] as $level) {
            $request = Requests::make();
            $request->context->threatLevel = $level;

            self::assertSame(299, $this->middleware()->process($request, self::next())->getStatus(), 'level ' . \var_export($level, true));
        }
    }

    public function testLevelFourIsRefusedWithRetryAfter(): void
    {
        $request = Requests::make(headers: ['Accept' => 'text/html']);
        $request->context->threatLevel = 4;

        $response = $this->middleware(window: 90)->process($request, self::failingNext());

        self::assertSame(429, $response->getStatus());
        self::assertSame('90', $response->getHeader('Retry-After'));
        self::assertSame('Too Many Requests', $response->getHeader('Botlock-Error'));
    }

    public function testTrustedGoodBotsAreRefusedToo(): void
    {
        $request = Requests::make(headers: ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);
        $request->context->threatLevel = 4;
        $request->context->crawlerVerification = CrawlerVerification::Verified;

        self::assertSame(429, $this->middleware()->process($request, self::failingNext())->getStatus());
    }

    public function testChallengeActionsAreRefusedButStatusIsNot(): void
    {
        $challenge = Requests::make(query: ['_botlock' => 'challenge']);
        $challenge->context->threatLevel = 4;
        self::assertSame(429, $this->middleware()->process($challenge, self::failingNext())->getStatus());

        $status = Requests::make(query: ['_botlock' => 'status']);
        $status->context->threatLevel = 4;
        self::assertSame(299, $this->middleware()->process($status, self::next())->getStatus());
    }

    private function middleware(int $window = 60): ThreatBlockMiddleware
    {
        return new ThreatBlockMiddleware(new RateLimitConfig(individualRateWindowSec: $window));
    }

    private static function next(): callable
    {
        return static fn (Request $request): Response => new Response(299);
    }

    private static function failingNext(): callable
    {
        return static function (): never {
            self::fail('a level-4 request must not continue');
        };
    }
}
