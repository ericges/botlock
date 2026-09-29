<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Crawler\CrawlerVerification;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Middleware\VerifyCrawlerMiddleware;
use GES\Botlock\Tests\Support\Requests;
use GES\Botlock\Tests\Support\StubCrawlerVerifier;
use PHPUnit\Framework\TestCase;

final class VerifyCrawlerMiddlewareTest extends TestCase
{
    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    private const IP = '66.249.66.1';

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

    public function testBrowsersAreLeftAlone(): void
    {
        $verifier = new StubCrawlerVerifier();
        $request = $this->request(threatLevel: 1);

        $this->process('Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0', $request, $verifier);

        self::assertNull($request->context->crawlerVerification);
        self::assertSame([], $verifier->calls);
        self::assertSame(1, $request->context->threatLevel);
    }

    public function testVerifiedGooglebotKeepsItsLevels(): void
    {
        $verifier = new StubCrawlerVerifier(CrawlerVerification::Verified);
        $request = $this->request(threatLevel: 1, individual: 1);

        $this->process(self::GOOGLEBOT, $request, $verifier);

        self::assertSame(CrawlerVerification::Verified, $request->context->crawlerVerification);
        self::assertSame([['google', self::IP]], $verifier->calls);
        self::assertSame(1, $request->context->threatLevel, 'verification no longer lowers the level');
        self::assertSame(1, $request->context->threatLevelIndividual);
    }

    public function testVerifierSelectionIgnoresUserAgentCasing(): void
    {
        foreach (['Googlebot/2.1', 'GOOGLEBOT/2.1', 'googlebot/2.1', 'AdsBot-Google (+http://www.google.com/adsbot.html)'] as $userAgent) {
            $verifier = new StubCrawlerVerifier();
            $request = $this->request(threatLevel: 1);

            $this->process($userAgent, $request, $verifier);

            self::assertSame([['google', self::IP]], $verifier->calls, $userAgent);
            self::assertSame(CrawlerVerification::Verified, $request->context->crawlerVerification, $userAgent);
        }
    }

    public function testFailedVerificationRaisesToAtLeastLevelTwoAndNeverLowers(): void
    {
        foreach ([null => 2, 0 => 2, 1 => 2, 2 => 2, 3 => 3] as $level => $expected) {
            $request = $this->request(threatLevel: $level === '' ? null : $level, individual: 0);

            $this->process(self::GOOGLEBOT, $request, new StubCrawlerVerifier(CrawlerVerification::Failed));

            self::assertSame(CrawlerVerification::Failed, $request->context->crawlerVerification);
            self::assertSame($expected, $request->context->threatLevel, "from level " . \var_export($level, true));
            self::assertSame(0, $request->context->threatLevelIndividual, 'the individual level is not touched');
        }
    }

    public function testUnverifiableResultIsRecordedWithoutChangingLevels(): void
    {
        $request = $this->request(threatLevel: 1);

        $this->process(self::GOOGLEBOT, $request, new StubCrawlerVerifier(CrawlerVerification::Unverified));

        self::assertSame(CrawlerVerification::Unverified, $request->context->crawlerVerification);
        self::assertSame(1, $request->context->threatLevel);
    }

    public function testGoodBotWithoutVerifierIsNotApplicable(): void
    {
        $verifier = new StubCrawlerVerifier();
        $request = $this->request(threatLevel: 1);

        $this->process('DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)', $request, $verifier);

        self::assertSame(CrawlerVerification::NotApplicable, $request->context->crawlerVerification);
        self::assertSame([], $verifier->calls);
    }

    public function testBingbotIsVerifiedByDns(): void
    {
        $verifier = new StubCrawlerVerifier(CrawlerVerification::Failed);
        $request = $this->request(threatLevel: 1);

        $this->process('Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', $request, $verifier);

        self::assertSame([['bing', self::IP]], $verifier->calls);
        self::assertSame(CrawlerVerification::Failed, $request->context->crawlerVerification);
        self::assertSame(2, $request->context->threatLevel, 'a spoofed Bingbot is challenged like a crawler');
    }

    public function testDisabledDnsChecksOrEmptyProviderListSkipVerification(): void
    {
        foreach ([new DetectionConfig(dnsChecks: false), new DetectionConfig(verifyBots: [])] as $config) {
            $verifier = new StubCrawlerVerifier();
            $request = $this->request(threatLevel: 1);

            $this->process(self::GOOGLEBOT, $request, $verifier, $config);

            self::assertSame(CrawlerVerification::NotApplicable, $request->context->crawlerVerification);
            self::assertSame([], $verifier->calls);
        }
    }

    public function testMissingClientIpLeavesTheBotUnverified(): void
    {
        $verifier = new StubCrawlerVerifier();
        $request = $this->request(threatLevel: 1, ip: null);

        $this->process(self::GOOGLEBOT, $request, $verifier);

        self::assertSame(CrawlerVerification::Unverified, $request->context->crawlerVerification);
        self::assertSame([], $verifier->calls);
    }

    public function testBlockedClientsCostNoLookups(): void
    {
        $verifier = new StubCrawlerVerifier();
        $request = $this->request(threatLevel: 4);

        $this->process(self::GOOGLEBOT, $request, $verifier);

        self::assertSame(CrawlerVerification::Unverified, $request->context->crawlerVerification);
        self::assertSame([], $verifier->calls);
        self::assertSame(4, $request->context->threatLevel);
    }

    public function testDefaultVerifierIsUsedWhenNoneIsInjected(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
        $middleware = new VerifyCrawlerMiddleware(new BotTestManager(new DetectionConfig()), new DetectionConfig());

        $response = $middleware->process($this->request(threatLevel: 0), static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
    }

    private function request(?int $threatLevel, ?int $individual = null, ?string $ip = self::IP): Request
    {
        $request = Requests::make();
        $request->context->clientIp = $ip;
        $request->context->threatLevel = $threatLevel;
        $request->context->threatLevelIndividual = $individual;

        return $request;
    }

    private function process(string $userAgent, Request $request, StubCrawlerVerifier $verifier, ?DetectionConfig $config = null): void
    {
        // CrawlerDetect reads the User-Agent from $_SERVER
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;
        $config ??= new DetectionConfig();

        $middleware = new VerifyCrawlerMiddleware(new BotTestManager($config), $config, $verifier);
        $response = $middleware->process($request, static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
    }
}
