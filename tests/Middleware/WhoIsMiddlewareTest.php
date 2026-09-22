<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Middleware\WhoIsMiddleware;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class WhoIsMiddlewareTest extends TestCase
{
    private const REMOTE = '203.0.113.10';
    private const FORWARDED = '198.51.100.7';
    private const WARNING = WhoIsMiddleware::WARNING_HEADER;

    public function testForwardedHeaderIsIgnoredWhenNoProxiesAreConfigured(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(trustedProxies: []), ['X-Forwarded-For' => self::FORWARDED . ', 10.0.0.1']);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('X-Forwarded-For', (string) $response->getHeader(self::WARNING));
        self::assertStringContainsString('BOTLOCK_TRUSTED_PROXIES', (string) $response->getHeader(self::WARNING));
    }

    public function testForwardedHeaderIsIgnoredFromUntrustedPeer(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(trustedProxies: ['10.9.9.9']), ['X-Forwarded-For' => self::FORWARDED]);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('peer ' . self::REMOTE, (string) $response->getHeader(self::WARNING));
    }

    public function testForwardedHeaderIsUsedFromTrustedProxy(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(trustedProxies: [self::REMOTE]), ['X-Real-Ip' => self::FORWARDED]);

        self::assertSame(self::FORWARDED, $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testDirectVisitorGetsNoWarning(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(), []);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testTrustedProxyMayBeAnIpv4Range(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(trustedProxies: ['203.0.113.0/24']), ['X-Forwarded-For' => self::FORWARDED]);

        self::assertSame(self::FORWARDED, $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testTrustedProxyMayBeAnIpv6Range(): void
    {
        [$request, $response] = $this->handle(
            new DetectionConfig(trustedProxies: ['2001:db8::/32']),
            ['X-Forwarded-For' => self::FORWARDED],
            ['REMOTE_ADDR' => '2001:db8::10'],
        );

        self::assertSame(self::FORWARDED, $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testMalformedRemoteAddrIsNeverTrusted(): void
    {
        [$request, $response] = $this->handle(
            new DetectionConfig(trustedProxies: ['0.0.0.0/0']),
            ['X-Forwarded-For' => self::FORWARDED],
            ['REMOTE_ADDR' => 'not-an-ip'],
        );

        self::assertNull($request->context->clientIp);
        self::assertNotNull($request->context->fingerprint);
        self::assertStringContainsString('remote address is not a valid IP', (string) $response->getHeader(self::WARNING));
    }

    public function testPrivateForwardedAddressFallsBackToRemoteAddr(): void
    {
        $request = $this->process(new DetectionConfig(trustedProxies: [self::REMOTE]), ['X-Forwarded-For' => '192.168.1.20']);

        self::assertSame(self::REMOTE, $request->context->clientIp);
    }

    public function testFingerprintIsStableAcrossHeaderValueOrder(): void
    {
        $a = $this->process(new DetectionConfig(), ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8', 'Accept-Encoding' => 'gzip, br']);
        $b = $this->process(new DetectionConfig(), ['Accept-Language' => 'en,de;q=0.5,de-DE', 'Accept-Encoding' => 'br,gzip']);

        self::assertNotNull($a->context->fingerprint);
        self::assertSame(64, \strlen($a->context->fingerprint));
        self::assertSame($a->context->fingerprint, $b->context->fingerprint);
    }

    public function testFingerprintChangesWithUserAgentAndIp(): void
    {
        $base = $this->process(new DetectionConfig(), []);
        $otherUa = $this->process(new DetectionConfig(), ['User-Agent' => 'Other/2.0']);
        $otherIp = $this->process(new DetectionConfig(), [], ['REMOTE_ADDR' => '203.0.113.99']);

        self::assertNotSame($base->context->fingerprint, $otherUa->context->fingerprint);
        self::assertNotSame($base->context->fingerprint, $otherIp->context->fingerprint);
    }

    public function testNextIsCalled(): void
    {
        $called = false;
        $request = Requests::make();

        (new WhoIsMiddleware(new DetectionConfig()))->process($request, function () use (&$called) {
            $called = true;
            return new PassResponse();
        });

        self::assertTrue($called);
    }

    private function process(DetectionConfig $config, array $headers, array $server = []): Request
    {
        return $this->handle($config, $headers, $server)[0];
    }

    /**
     * @return array{Request, Response}
     */
    private function handle(DetectionConfig $config, array $headers, array $server = []): array
    {
        $request = Requests::make(headers: $headers, server: $server);
        $response = (new WhoIsMiddleware($config))->process($request, static fn(): PassResponse => new PassResponse());

        return [$request, $response];
    }
}
