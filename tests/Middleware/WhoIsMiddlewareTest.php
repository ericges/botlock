<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Middleware\WhoIsMiddleware;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class WhoIsMiddlewareTest extends TestCase
{
    private const REMOTE = '203.0.113.10';
    private const FORWARDED = '198.51.100.7';

    public function testForwardedHeaderIsTrustedWhenNoProxiesAreConfigured(): void
    {
        $request = $this->process(new DetectionConfig(trustedProxies: []), ['X-Forwarded-For' => self::FORWARDED . ', 10.0.0.1']);

        self::assertSame(self::FORWARDED, $request->context->clientIp);
    }

    public function testForwardedHeaderIsIgnoredFromUntrustedPeer(): void
    {
        $request = $this->process(new DetectionConfig(trustedProxies: ['10.9.9.9']), ['X-Forwarded-For' => self::FORWARDED]);

        self::assertSame(self::REMOTE, $request->context->clientIp);
    }

    public function testForwardedHeaderIsUsedFromTrustedProxy(): void
    {
        $request = $this->process(new DetectionConfig(trustedProxies: [self::REMOTE]), ['X-Real-Ip' => self::FORWARDED]);

        self::assertSame(self::FORWARDED, $request->context->clientIp);
    }

    public function testPrivateForwardedAddressFallsBackToRemoteAddr(): void
    {
        $request = $this->process(new DetectionConfig(), ['X-Forwarded-For' => '192.168.1.20']);

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
        $request = Requests::make(headers: $headers, server: $server);
        (new WhoIsMiddleware($config))->process($request, static fn(): PassResponse => new PassResponse());

        return $request;
    }
}
