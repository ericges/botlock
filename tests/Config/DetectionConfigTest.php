<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Config;

use GES\Botlock\Config\DetectionConfig;
use PHPUnit\Framework\TestCase;

final class DetectionConfigTest extends TestCase
{
    public function testDefaultsTrustNoProxies(): void
    {
        self::assertSame([], (new DetectionConfig())->trustedProxies);
    }

    public function testAcceptsAddressesAndCidrRanges(): void
    {
        $proxies = ['203.0.113.10', '10.0.0.0/8', '2001:db8::/32', 'fd00::1'];

        self::assertSame($proxies, (new DetectionConfig(trustedProxies: $proxies))->trustedProxies);
    }

    public function testRejectsMalformedTrustedProxy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('203.0.113.0/33');

        new DetectionConfig(trustedProxies: ['203.0.113.10', '203.0.113.0/33']);
    }

    public function testRejectsNonAddressTrustedProxy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy.example');

        new DetectionConfig(trustedProxies: ['proxy.example']);
    }
}
