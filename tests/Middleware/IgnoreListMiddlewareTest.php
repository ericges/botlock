<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Manager\WhitelistManager;
use GES\Botlock\Middleware\IgnoreListMiddleware;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class IgnoreListMiddlewareTest extends TestCase
{
    private IgnoreListMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new IgnoreListMiddleware(new WhitelistManager(new DetectionConfig(
            ignoreIps: ['203.0.113.50'],
            ignoreUserAgents: ['UptimeMonitor'],
            ignoreUrls: ['https://example.test/health'],
        )));
    }

    public function testIgnoredUserAgentPassesWithoutReachingNext(): void
    {
        $request = Requests::make(headers: ['User-Agent' => 'uptimemonitor/3.1 (+https://x)']);

        $response = $this->middleware->process($request, $this->failingNext());

        self::assertInstanceOf(PassResponse::class, $response);
    }

    public function testIgnoredIpPasses(): void
    {
        $request = Requests::make();
        $request->context->clientIp = '203.0.113.50';

        self::assertInstanceOf(PassResponse::class, $this->middleware->process($request, $this->failingNext()));
    }

    public function testIgnoredUrlPrefixPasses(): void
    {
        $request = Requests::make(url: 'https://example.test/health/db');

        self::assertInstanceOf(PassResponse::class, $this->middleware->process($request, $this->failingNext()));
    }

    public function testOtherRequestsContinue(): void
    {
        $request = Requests::make(url: 'https://example.test/shop');
        $request->context->clientIp = '203.0.113.51';

        $response = $this->middleware->process($request, static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
    }

    public function testBotlockActionsAreNeverShortCircuited(): void
    {
        $request = Requests::make(headers: ['User-Agent' => 'UptimeMonitor/1'], query: ['_botlock' => 'status']);

        $response = $this->middleware->process($request, static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
    }

    private function failingNext(): callable
    {
        return static function (): Response {
            self::fail('next() must not be called for an ignored request');
        };
    }
}
