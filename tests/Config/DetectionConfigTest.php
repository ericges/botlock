<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Config;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Http\IpMatcher;
use PHPUnit\Framework\TestCase;

final class DetectionConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        \putenv('BOTLOCK_TRUSTED_PROXIES');
        \putenv('BOTLOCK_EXTERNAL_SCHEME');
        \putenv('BOTLOCK_VERIFY_BOTS');
    }

    public function testEveryVerifiableProviderIsVerifiedByDefault(): void
    {
        self::assertSame(['google', 'bing'], \array_keys(DetectionConfig::fromEnv()->verifyBots));
    }

    public function testVerifyBotsSelectsKnownProviders(): void
    {
        \putenv('BOTLOCK_VERIFY_BOTS=Bing, unknown');

        self::assertSame(['bing' => ['Bingbot']], DetectionConfig::fromEnv()->verifyBots);
    }

    public function testExternalSchemeDefaultsToAuto(): void
    {
        self::assertNull((new DetectionConfig())->externalScheme);
        self::assertNull(DetectionConfig::fromEnv()->externalScheme);

        \putenv('BOTLOCK_EXTERNAL_SCHEME=auto');
        self::assertNull(DetectionConfig::fromEnv()->externalScheme);

        \putenv('BOTLOCK_EXTERNAL_SCHEME=');
        self::assertNull(DetectionConfig::fromEnv()->externalScheme);
    }

    public function testExternalSchemeFromEnvIsNormalized(): void
    {
        \putenv('BOTLOCK_EXTERNAL_SCHEME= HTTPS ');

        self::assertSame('https', DetectionConfig::fromEnv()->externalScheme);
    }

    public function testRejectsUnknownExternalScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ftp');

        new DetectionConfig(externalScheme: 'ftp');
    }

    public function testRejectsUnknownExternalSchemeFromEnv(): void
    {
        \putenv('BOTLOCK_EXTERNAL_SCHEME=ftp');

        $this->expectException(\InvalidArgumentException::class);

        DetectionConfig::fromEnv();
    }

    public function testDefaultsTrustPrivateAndLoopbackPeers(): void
    {
        $proxies = (new DetectionConfig())->trustedProxies;

        self::assertSame(DetectionConfig::DEFAULT_TRUSTED_PROXIES, $proxies);

        foreach (['127.0.0.1', '10.1.2.3', '172.18.0.5', '192.168.1.1', '169.254.1.1', '::1', 'fd12::1', 'fe80::1'] as $ip) {
            self::assertTrue(IpMatcher::matchesAny($ip, $proxies), $ip);
        }

        foreach (['203.0.113.10', '172.32.0.1', '2001:db8::1'] as $ip) {
            self::assertFalse(IpMatcher::matchesAny($ip, $proxies), $ip);
        }
    }

    public function testUnsetEnvUsesDefaultProxies(): void
    {
        self::assertSame(DetectionConfig::DEFAULT_TRUSTED_PROXIES, DetectionConfig::fromEnv()->trustedProxies);
    }

    public function testEmptyEnvTrustsNobody(): void
    {
        \putenv('BOTLOCK_TRUSTED_PROXIES=');
        self::assertSame([], DetectionConfig::fromEnv()->trustedProxies);

        \putenv('BOTLOCK_TRUSTED_PROXIES=[]');
        self::assertSame([], DetectionConfig::fromEnv()->trustedProxies);
    }

    public function testExplicitEnvListReplacesDefaults(): void
    {
        \putenv('BOTLOCK_TRUSTED_PROXIES=203.0.113.10, 2001:db8::/32');

        self::assertSame(['203.0.113.10', '2001:db8::/32'], DetectionConfig::fromEnv()->trustedProxies);
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

    public function testRejectsMalformedEnvEntry(): void
    {
        \putenv('BOTLOCK_TRUSTED_PROXIES=10.0.0.0/8,garbage');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('garbage');

        DetectionConfig::fromEnv();
    }
}
