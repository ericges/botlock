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
        self::assertStringContainsString('BOTLOCK_TRUSTED_PROXIES is empty', (string) $response->getHeader(self::WARNING));
    }

    public function testPrivatePeerIsTrustedByDefault(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(), ['X-Forwarded-For' => self::FORWARDED], ['REMOTE_ADDR' => '172.18.0.5']);

        self::assertSame(self::FORWARDED, $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testPublicPeerIsNotTrustedByDefault(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(), ['X-Forwarded-For' => self::FORWARDED]);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('peer ' . self::REMOTE, (string) $response->getHeader(self::WARNING));
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
        [$request, $response] = $this->handle(new DetectionConfig(trustedProxies: [self::REMOTE]), ['X-Forwarded-For' => '192.168.1.20']);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('hop 192.168.1.20 is not a trusted proxy', (string) $response->getHeader(self::WARNING));
    }

    // --- X-Forwarded-For chain, read from the right ---

    public function testSpoofedLeftEntryIsIgnoredBehindAppendingProxy(): void
    {
        [$request, $response] = $this->trusted(['X-Forwarded-For' => '1.2.3.4, ' . self::FORWARDED]);

        self::assertSame(self::FORWARDED, $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testListedInternalHopsAreSkipped(): void
    {
        [$request, $response] = $this->trusted(['X-Forwarded-For' => self::FORWARDED . ', 10.0.0.2'], extraProxies: ['10.0.0.0/8']);

        self::assertSame(self::FORWARDED, $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testUnlistedInternalHopFallsBackWithWarning(): void
    {
        [$request, $response] = $this->trusted(['X-Forwarded-For' => self::FORWARDED . ', 10.0.0.2']);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('hop 10.0.0.2 is not a trusted proxy', (string) $response->getHeader(self::WARNING));
    }

    public function testUnlistedPublicHopIsTakenForTheClient(): void
    {
        // An unlisted proxy with a public address (e.g. a forgotten CDN) is indistinguishable
        // from a visitor, so the chain walk stops there without a warning.
        [$request, $response] = $this->trusted(['X-Forwarded-For' => self::FORWARDED . ', 192.0.2.50']);

        self::assertSame('192.0.2.50', $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testMalformedEntryFallsBackWithWarning(): void
    {
        [$request, $response] = $this->trusted(['X-Forwarded-For' => self::FORWARDED . ', not-an-ip']);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('malformed entry at position 2', (string) $response->getHeader(self::WARNING));
        self::assertStringNotContainsString('not-an-ip', (string) $response->getHeader(self::WARNING));

        [$request, $response] = $this->trusted(['X-Forwarded-For' => self::FORWARDED . ',']);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('malformed entry at position 2', (string) $response->getHeader(self::WARNING));
    }

    public function testEntriesLeftOfTheClientAreNeverInspected(): void
    {
        [$request, $response] = $this->trusted(['X-Forwarded-For' => 'garbage, ' . self::FORWARDED]);

        self::assertSame(self::FORWARDED, $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testChainOfOnlyTrustedProxiesFallsBackSilently(): void
    {
        [$request, $response] = $this->trusted(['X-Forwarded-For' => self::REMOTE]);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testOverlongChainIsRejected(): void
    {
        [$request, $response] = $this->trusted(['X-Forwarded-For' => \implode(', ', \array_fill(0, 33, self::FORWARDED))]);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('more than 32 entries', (string) $response->getHeader(self::WARNING));
    }

    public function testPortsAndBracketsAreStripped(): void
    {
        [$request] = $this->trusted(['X-Forwarded-For' => self::FORWARDED . ':51234']);
        self::assertSame(self::FORWARDED, $request->context->clientIp);

        [$request, $response] = $this->trusted(['X-Forwarded-For' => '[2001:4860:4860::7]:443, 10.0.0.2'], extraProxies: ['10.0.0.0/8']);
        self::assertSame('2001:4860:4860::7', $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));

        [$request] = $this->trusted(['X-Forwarded-For' => '[2001:4860:4860::7]']);
        self::assertSame('2001:4860:4860::7', $request->context->clientIp);
    }

    public function testForwardedForIsAuthoritativeOverRealIp(): void
    {
        [$request, $response] = $this->trusted(['X-Forwarded-For' => '10.0.0.2', 'X-Real-Ip' => self::FORWARDED]);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('hop 10.0.0.2', (string) $response->getHeader(self::WARNING));
    }

    // --- single-valued headers ---

    public function testRealIpPointingAtTrustedProxyIsIgnored(): void
    {
        [$request, $response] = $this->trusted(['X-Real-Ip' => '10.0.0.2'], extraProxies: ['10.0.0.0/8']);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('10.0.0.2 is a trusted proxy', (string) $response->getHeader(self::WARNING));
    }

    public function testPrivateRealIpIsIgnored(): void
    {
        [$request, $response] = $this->trusted(['X-Real-Ip' => '192.168.1.20']);

        self::assertSame(self::REMOTE, $request->context->clientIp);
        self::assertStringContainsString('192.168.1.20 is not a public address', (string) $response->getHeader(self::WARNING));
    }

    public function testMalformedRealIpIsIgnored(): void
    {
        [$request, $response] = $this->trusted(['X-Real-Ip' => 'nope', 'Client-Ip' => self::FORWARDED]);

        self::assertSame(self::REMOTE, $request->context->clientIp, 'only the first present single-value header is evaluated');
        self::assertStringContainsString('X-Real-Ip ignored: malformed value', (string) $response->getHeader(self::WARNING));
    }

    public function testRealIpWithPortIsAccepted(): void
    {
        [$request, $response] = $this->trusted(['X-Real-Ip' => self::FORWARDED . ':8443']);

        self::assertSame(self::FORWARDED, $request->context->clientIp);
        self::assertFalse($response->hasHeader(self::WARNING));
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

    /**
     * Runs the middleware with REMOTE trusted as a proxy.
     *
     * @return array{Request, Response}
     */
    public function testForwardedProtoFromTrustedProxyMakesTheRequestSecure(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(trustedProxies: [self::REMOTE]), ['X-Forwarded-Proto' => 'https'], url: 'http://example.test/page?x=1');

        self::assertTrue($request->isSecure());
        self::assertSame('https://example.test', $request->getOrigin());
        self::assertSame('https://example.test/page?x=1', $request->getRequestUrl());
        self::assertFalse($response->hasHeader(self::WARNING));
    }

    public function testForwardedProtoFromUntrustedPeerIsIgnoredWithWarning(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(trustedProxies: ['10.9.9.9']), ['X-Forwarded-Proto' => 'https'], url: 'http://example.test/');

        self::assertFalse($request->isSecure());
        self::assertSame('http://example.test', $request->getOrigin());
        self::assertStringContainsString('X-Forwarded-Proto', (string) $response->getHeader(self::WARNING));
        self::assertStringContainsString('peer ' . self::REMOTE, (string) $response->getHeader(self::WARNING));
    }

    public function testMalformedForwardedProtoIsIgnoredAndNotEchoed(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(trustedProxies: [self::REMOTE]), ['X-Forwarded-Proto' => "ftp\r\nX-Evil: 1"], url: 'http://example.test/');

        self::assertFalse($request->isSecure());
        self::assertStringContainsString('X-Forwarded-Proto ignored: malformed value', (string) $response->getHeader(self::WARNING));
        self::assertStringNotContainsString('ftp', (string) $response->getHeader(self::WARNING));
    }

    public function testForwardedProtoChainUsesTheClientFacingHop(): void
    {
        [$request] = $this->handle(new DetectionConfig(trustedProxies: [self::REMOTE]), ['X-Forwarded-Proto' => 'https, http'], url: 'http://example.test/');

        self::assertTrue($request->isSecure());
    }

    public function testTrustedProxyMayDowngradeToHttp(): void
    {
        [$request] = $this->handle(new DetectionConfig(trustedProxies: [self::REMOTE]), ['X-Forwarded-Proto' => 'http'], url: 'https://example.test/');

        self::assertFalse($request->isSecure());
        self::assertSame('http://example.test', $request->getOrigin());
    }

    public function testExternalSchemeSettingWinsOverHeadersAndPeers(): void
    {
        [$request, $response] = $this->handle(new DetectionConfig(trustedProxies: [], externalScheme: 'https'), [], url: 'http://example.test/');
        self::assertTrue($request->isSecure(), 'forced https without any header');
        self::assertFalse($response->hasHeader(self::WARNING));

        [$request] = $this->handle(new DetectionConfig(trustedProxies: [self::REMOTE], externalScheme: 'http'), ['X-Forwarded-Proto' => 'https'], url: 'https://example.test/');
        self::assertFalse($request->isSecure(), 'forced http beats a trusted https header');
    }

    public function testIpAndSchemeWarningsAreBothReported(): void
    {
        [, $response] = $this->handle(new DetectionConfig(trustedProxies: ['10.9.9.9']), ['X-Forwarded-For' => self::FORWARDED, 'X-Forwarded-Proto' => 'https'], url: 'http://example.test/');

        $warning = (string) $response->getHeader(self::WARNING);
        self::assertStringContainsString('forwarding header X-Forwarded-For ignored', $warning);
        self::assertStringContainsString('forwarding header X-Forwarded-Proto ignored', $warning);
    }

    private function trusted(array $headers, array $extraProxies = []): array
    {
        return $this->handle(new DetectionConfig(trustedProxies: [self::REMOTE, ...$extraProxies]), $headers);
    }

    private function process(DetectionConfig $config, array $headers, array $server = []): Request
    {
        return $this->handle($config, $headers, $server)[0];
    }

    /**
     * @return array{Request, Response}
     */
    private function handle(DetectionConfig $config, array $headers, array $server = [], string $url = 'https://example.test/'): array
    {
        $request = Requests::make(url: $url, headers: $headers, server: $server);
        $response = (new WhoIsMiddleware($config))->process($request, static fn(): PassResponse => new PassResponse());

        return [$request, $response];
    }
}
