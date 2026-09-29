<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Crawler;

use GES\Botlock\Crawler\CrawlerVerification;
use GES\Botlock\Crawler\DnsCrawlerVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DnsCrawlerVerifierTest extends TestCase
{
    private const IP = '203.0.113.7';
    private const IPV6 = '2001:db8::7';

    public static function lookups(): iterable
    {
        yield 'googlebot' => ['google', self::IP, 'crawl-203-0-113-7.googlebot.com', [['ip' => self::IP]], CrawlerVerification::Verified];
        yield 'google.com host' => ['google', self::IP, 'rate-limited-proxy.google.com', [['ip' => self::IP]], CrawlerVerification::Verified];
        yield 'bingbot' => ['bing', self::IP, 'msnbot-203-0-113-7.search.msn.com', [['ip' => self::IP]], CrawlerVerification::Verified];
        yield 'upper-case host' => ['bing', self::IP, 'MSNBOT-203-0-113-7.SEARCH.MSN.COM', [['ip' => self::IP]], CrawlerVerification::Verified];
        yield 'ipv6' => ['bing', self::IPV6, 'msnbot-7.search.msn.com', [['ipv6' => self::IPV6]], CrawlerVerification::Verified];
        yield 'another provider\'s host' => ['bing', self::IP, 'crawl.googlebot.com', [['ip' => self::IP]], CrawlerVerification::Failed];
        yield 'lookalike host' => ['bing', self::IP, 'search.msn.com.evil.test', [['ip' => self::IP]], CrawlerVerification::Failed];
        yield 'suffix without dot' => ['bing', self::IP, 'evilsearch.msn.com', [['ip' => self::IP]], CrawlerVerification::Failed];
        yield 'forward misses the ip' => ['bing', self::IP, 'msnbot.search.msn.com', [['ip' => '198.51.100.1']], CrawlerVerification::Failed];
        yield 'no forward records' => ['google', self::IP, 'crawl.googlebot.com', false, CrawlerVerification::Failed];
        yield 'no reverse name' => ['google', self::IP, false, [['ip' => self::IP]], CrawlerVerification::Failed];
        yield 'reverse gives the ip back' => ['google', self::IP, self::IP, [['ip' => self::IP]], CrawlerVerification::Failed];
    }

    #[DataProvider('lookups')]
    public function testVerifiesByReverseAndForwardLookup(string $provider, string $ip, string|false $host, array|false $records, CrawlerVerification $expected): void
    {
        $verifier = new DnsCrawlerVerifier(
            reverse: static fn(string $address): string|false => $address === $ip ? $host : false,
            forward: static fn(string $name): array|false => \is_string($host) && $name === \strtolower($host) ? $records : false,
        );

        self::assertSame($expected, $verifier->verify($provider, $ip));
    }

    public function testUnknownProviderIsNotLookedUp(): void
    {
        $verifier = new DnsCrawlerVerifier(
            reverse: static fn(): never => throw new \LogicException('no lookup expected'),
            forward: static fn(): never => throw new \LogicException('no lookup expected'),
        );

        self::assertSame(CrawlerVerification::Unverified, $verifier->verify('duckduckgo', self::IP));
    }
}
