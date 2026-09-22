<?php declare(strict_types=1);

namespace GES\Botlock\Http;

/**
 * Matches IP addresses against rules that are either a single address
 * or a CIDR range (network/prefix), for IPv4 and IPv6.
 *
 * Address families are never mixed: an IPv4 address does not match an
 * IPv6 rule and vice versa, and IPv4-mapped IPv6 addresses (::ffff:a.b.c.d)
 * are not folded onto their IPv4 counterpart.
 */
final readonly class IpMatcher
{
    /**
     * Whether $rule is a well-formed address or CIDR range.
     */
    public static function isValidRule(string $rule): bool
    {
        return self::parseRule($rule) !== null;
    }

    /**
     * Whether $ip lies within $rule. Returns false, never throws, for
     * malformed input on either side.
     */
    public static function matches(string $ip, string $rule): bool
    {
        if (\filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $parsed = self::parseRule($rule);

        if ($parsed === null) {
            return false;
        }

        [$network, $prefix] = $parsed;
        $address = \inet_pton($ip);

        if ($address === false || \strlen($address) !== \strlen($network)) {
            return false;
        }

        $fullBytes = \intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && \strncmp($address, $network, $fullBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (\ord($address[$fullBytes]) & $mask) === (\ord($network[$fullBytes]) & $mask);
    }

    /**
     * Whether $ip matches at least one of $rules.
     *
     * @param string[] $rules
     */
    public static function matchesAny(string $ip, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (self::matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{string,int}|null packed network address and prefix length, or null if malformed
     */
    private static function parseRule(string $rule): ?array
    {
        if ($rule === '') {
            return null;
        }

        $parts = \explode('/', $rule, 2);
        $network = $parts[0];
        $prefix = $parts[1] ?? null;

        if (\filter_var($network, \FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = \inet_pton($network);

        if ($packed === false) {
            return null;
        }

        $maxPrefix = \strlen($packed) * 8;

        if ($prefix === null) {
            return [$packed, $maxPrefix];
        }

        if ($prefix === '' || !\ctype_digit($prefix)) {
            return null;
        }

        $prefix = (int) $prefix;

        if ($prefix > $maxPrefix) {
            return null;
        }

        return [$packed, $prefix];
    }
}
