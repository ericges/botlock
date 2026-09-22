<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Middleware\ThreatPassMiddleware;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ThreatPassMiddlewareTest extends TestCase
{
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
        $request = Requests::make();
        $request->context->threatLevel = 0;

        self::assertInstanceOf(PassResponse::class, $this->middleware('Mozilla/5.0')->process($request, $this->failingNext()));
    }

    public function testElevatedLevelContinuesForRegularClients(): void
    {
        $request = Requests::make();
        $request->context->threatLevel = 1;
        $request->context->threatLevelIndividual = 1;

        $response = $this->middleware('Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0')->process($request, static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
    }

    public function testGoodBotPassesWhileIndividualLevelBelowTwo(): void
    {
        $request = Requests::make();
        $request->context->threatLevel = 1;
        $request->context->threatLevelIndividual = 1;

        self::assertInstanceOf(PassResponse::class, $this->middleware('Googlebot/2.1 (+http://www.google.com/bot.html)')->process($request, $this->failingNext()));
    }

    public function testGoodBotIsChallengedAtIndividualLevelTwo(): void
    {
        $request = Requests::make();
        $request->context->threatLevel = 2;
        $request->context->threatLevelIndividual = 2;

        $response = $this->middleware('Googlebot/2.1 (+http://www.google.com/bot.html)')->process($request, static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
    }

    public function testUnknownCrawlerIsNotAGoodBot(): void
    {
        $request = Requests::make();
        $request->context->threatLevel = 1;
        $request->context->threatLevelIndividual = 0;

        $response = $this->middleware('Scrapy/2.11 (+https://scrapy.org)')->process($request, static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
    }

    public function testActionsAlwaysContinue(): void
    {
        $request = Requests::make(query: ['_botlock' => 'status']);
        $request->context->threatLevel = 0;

        $response = $this->middleware('Mozilla/5.0')->process($request, static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
    }

    private function middleware(string $userAgent): ThreatPassMiddleware
    {
        // CrawlerDetect reads the User-Agent from $_SERVER
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;

        return new ThreatPassMiddleware(new BotTestManager(new DetectionConfig()));
    }

    private function failingNext(): callable
    {
        return static function (): Response {
            self::fail('next() must not be called');
        };
    }
}
