<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Http;

use GES\Botlock\Http\IpMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpMatcherTest extends TestCase
{
    #[DataProvider('validRules')]
    public function testValidRulesAreAccepted(string $rule): void
    {
        self::assertTrue(IpMatcher::isValidRule($rule), $rule);
    }

    public static function validRules(): iterable
    {
        foreach (['203.0.113.10', '203.0.113.0/24', '10.0.0.0/8', '0.0.0.0/0', '203.0.113.10/32',
                  '2001:db8::1', '2001:db8::/32', '::/0', '2001:db8::/128', 'fd00::/7'] as $rule) {
            yield $rule => [$rule];
        }
    }

    #[DataProvider('invalidRules')]
    public function testInvalidRulesAreRejected(string $rule): void
    {
        self::assertFalse(IpMatcher::isValidRule($rule), $rule);
        self::assertFalse(IpMatcher::matches('203.0.113.10', $rule), $rule);
    }

    public static function invalidRules(): iterable
    {
        foreach (['', 'not-an-ip', '203.0.113.0/33', '2001:db8::/129', '203.0.113.0/-1', '203.0.113.0/abc',
                  '/24', '203.0.113.0/', '203.0.113.0/24/1', '203.0.113', '203.0.113.0/ 24', '203.0.113.0/+8'] as $rule) {
            yield ($rule === '' ? 'empty' : $rule) => [$rule];
        }
    }

    #[DataProvider('matchingPairs')]
    public function testMatches(string $ip, string $rule, bool $expected): void
    {
        self::assertSame($expected, IpMatcher::matches($ip, $rule), "$ip vs $rule");
    }

    public static function matchingPairs(): iterable
    {
        // IPv4 exact
        yield ['203.0.113.10', '203.0.113.10', true];
        yield ['203.0.113.11', '203.0.113.10', false];
        yield ['203.0.113.10', '203.0.113.10/32', true];
        yield ['203.0.113.11', '203.0.113.10/32', false];
        // IPv4 ranges
        yield ['203.0.113.255', '203.0.113.0/24', true];
        yield ['203.0.114.0', '203.0.113.0/24', false];
        yield ['10.255.255.255', '10.0.0.0/8', true];
        yield ['11.0.0.0', '10.0.0.0/8', false];
        yield ['203.0.113.130', '203.0.113.128/25', true];
        yield ['203.0.113.127', '203.0.113.128/25', false];
        yield ['198.51.100.1', '0.0.0.0/0', true];
        // network given with host bits set still matches its range
        yield ['203.0.113.1', '203.0.113.77/24', true];
        // IPv6 exact
        yield ['2001:db8::1', '2001:db8::1', true];
        yield ['2001:db8::1', '2001:db8:0:0:0:0:0:1', true];
        yield ['2001:db8::2', '2001:db8::1/128', false];
        // IPv6 ranges
        yield ['2001:db8:ffff::1', '2001:db8::/32', true];
        yield ['2001:db9::1', '2001:db8::/32', false];
        yield ['2001:db8:8000::1', '2001:db8:8000::/33', true];
        yield ['2001:db8:7fff::1', '2001:db8:8000::/33', false];
        yield ['fd12:3456::1', 'fd00::/7', true];
        yield ['fe80::1', 'fd00::/7', false];
        yield ['2001:db8::1', '::/0', true];
        // family mismatch
        yield ['203.0.113.10', '::/0', false];
        yield ['2001:db8::1', '0.0.0.0/0', false];
        yield ['::ffff:203.0.113.10', '203.0.113.0/24', false];
        // malformed address
        yield ['not-an-ip', '0.0.0.0/0', false];
        yield ['', '0.0.0.0/0', false];
    }

    public function testMatchesAny(): void
    {
        $rules = ['10.0.0.0/8', '2001:db8::/32', '203.0.113.10'];

        self::assertTrue(IpMatcher::matchesAny('10.1.2.3', $rules));
        self::assertTrue(IpMatcher::matchesAny('2001:db8::42', $rules));
        self::assertTrue(IpMatcher::matchesAny('203.0.113.10', $rules));
        self::assertFalse(IpMatcher::matchesAny('203.0.113.11', $rules));
        self::assertFalse(IpMatcher::matchesAny('203.0.113.10', []));
    }
}
