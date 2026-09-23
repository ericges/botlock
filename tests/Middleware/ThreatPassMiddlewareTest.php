<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Crawler\CrawlerVerification;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Middleware\ThreatPassMiddleware;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ThreatPassMiddlewareTest extends TestCase
{
    private const GOOGLEBOT = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
    private const BINGBOT = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';

    private ?string $originalUserAgent;

    protected function setUp(): void
    {
        $this->originalUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalUserAgent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $this->originalUserAgent;
        }
    }

    public function testLevelZeroPasses(): void
    {
        $request = $this->request(threatLevel: 0);

        self::assertInstanceOf(PassResponse::class, $this->middleware('Mozilla/5.0')->process($request, $this->failingNext()));
    }

    public function testElevatedLevelContinuesForRegularClients(): void
    {
        $request = $this->request(threatLevel: 1, individual: 1);

        self::assertChallenged($this->middleware('Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0'), $request);
    }

    public function testVerifiedGoodBotPassesWhileEffectiveLevelBelowTwo(): void
    {
        $request = $this->request(threatLevel: 1, individual: 1, verification: CrawlerVerification::Verified);

        self::assertInstanceOf(PassResponse::class, $this->middleware(self::GOOGLEBOT)->process($request, $this->failingNext()));
    }

    public function testGoodBotWithoutVerifierPassesWhileEffectiveLevelBelowTwo(): void
    {
        $request = $this->request(threatLevel: 1, individual: 0, verification: CrawlerVerification::NotApplicable);

        self::assertInstanceOf(PassResponse::class, $this->middleware(self::BINGBOT)->process($request, $this->failingNext()));
    }

    public function testGoodBotIsChallengedAtLevelTwoAndThreeRegardlessOfIndividualLevel(): void
    {
        foreach ([2, 3] as $level) {
            // A global event: the individual counter of this bot is still at 0.
            $request = $this->request(threatLevel: $level, individual: 0, global: $level, verification: CrawlerVerification::Verified);
            self::assertChallenged($this->middleware(self::GOOGLEBOT), $request, "verified Googlebot at level $level");

            $request = $this->request(threatLevel: $level, individual: 0, global: $level, verification: CrawlerVerification::NotApplicable);
            self::assertChallenged($this->middleware(self::BINGBOT), $request, "bingbot at level $level");
        }
    }

    public function testGoodBotIsChallengedWhenVerificationFailedOrDidNotRun(): void
    {
        foreach ([CrawlerVerification::Failed, CrawlerVerification::Unverified, null] as $verification) {
            $request = $this->request(threatLevel: 1, individual: 0, verification: $verification);

            self::assertChallenged($this->middleware(self::GOOGLEBOT), $request, \var_export($verification?->value, true));
        }
    }

    public function testGoodBotPassesUnderAnOverrideWithoutIndividualLevel(): void
    {
        // BOTLOCK_THREAT_LEVEL_OVERRIDE=1 leaves the individual level unset.
        $request = $this->request(threatLevel: 1, individual: null, verification: CrawlerVerification::NotApplicable);

        self::assertInstanceOf(PassResponse::class, $this->middleware(self::BINGBOT)->process($request, $this->failingNext()));
    }

    public function testUnknownCrawlerIsNotAGoodBot(): void
    {
        $request = $this->request(threatLevel: 1, individual: 0, verification: CrawlerVerification::NotApplicable);

        self::assertChallenged($this->middleware('Scrapy/2.11 (+https://scrapy.org)'), $request);
    }

    public function testActionsAlwaysContinue(): void
    {
        $request = Requests::make(query: ['_botlock' => 'status']);
        $request->context->threatLevel = 0;

        $response = $this->middleware('Mozilla/5.0')->process($request, static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
    }

    private function request(?int $threatLevel, ?int $individual = null, ?int $global = null, ?CrawlerVerification $verification = null): Request
    {
        $request = Requests::make();
        $request->context->threatLevel = $threatLevel;
        $request->context->threatLevelIndividual = $individual;
        $request->context->threatLevelGlobal = $global;
        $request->context->crawlerVerification = $verification;

        return $request;
    }

    private function middleware(string $userAgent): ThreatPassMiddleware
    {
        // CrawlerDetect reads the User-Agent from $_SERVER
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;

        return new ThreatPassMiddleware(new BotTestManager(new DetectionConfig()));
    }

    private static function assertChallenged(ThreatPassMiddleware $middleware, Request $request, string $message = ''): void
    {
        $response = $middleware->process($request, static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus(), $message);
    }

    private function failingNext(): callable
    {
        return static function (): Response {
            self::fail('next() must not be called');
        };
    }
}
