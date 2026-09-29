<?php declare(strict_types=1);

namespace GES\Botlock\Config;

use GES\Botlock\Http\IpMatcher;

/**
 * Who to trust and who to leave alone: exclusions, good-bot names,
 * DNS verification and proxy trust.
 */
final readonly class DetectionConfig
{
    public const DEFAULT_GOOD_BOTS = ['Googlebot', 'AdsBot', 'Bingbot', 'DuckDuckBot', 'Exabot', 'facebot'];

    /** Providers that can be verified via DNS, mapped to the CrawlerDetect names they cover. */
    public const VERIFIABLE_BOTS = [
        'google' => ['Googlebot', 'AdsBot'],
        'bing' => ['Bingbot'],
    ];

    /** Values BOTLOCK_EXTERNAL_SCHEME and a trusted X-Forwarded-Proto may take. */
    public const EXTERNAL_SCHEMES = ['http', 'https'];

    /**
     * Peers trusted to supply forwarding headers when BOTLOCK_TRUSTED_PROXIES is unset:
     * loopback, RFC 1918 private, link-local and IPv6 unique-local ranges (the same
     * list Symfony and Laravel trust by default).
     */
    public const DEFAULT_TRUSTED_PROXIES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    /**
     * @param string[]                $ignoreIps        Exact client IPs that bypass botlock
     * @param string[]                $ignoreUserAgents User-Agent substrings that bypass botlock (case-insensitive)
     * @param string[]                $ignoreUrls       Absolute URL prefixes that bypass botlock
     * @param string[]                $goodBots         CrawlerDetect names treated as good bots
     * @param array<string,string[]>  $verifyBots       Subset of VERIFIABLE_BOTS to verify via DNS
     * @param string[]                $trustedProxies   Proxy IPs or CIDR ranges whose forwarding headers are trusted; empty trusts none, unset env uses DEFAULT_TRUSTED_PROXIES
     * @param bool                    $dnsChecks        Master switch for DNS verification
     * @param string|null             $externalScheme   'http' or 'https' forces the scheme visitors use; null derives it from HTTPS/port and a trusted X-Forwarded-Proto
     *
     * @throws \InvalidArgumentException on a malformed trusted proxy entry or external scheme
     */
    public function __construct(
        public array   $ignoreIps = [],
        public array   $ignoreUserAgents = [],
        public array   $ignoreUrls = [],
        public array   $goodBots = self::DEFAULT_GOOD_BOTS,
        public array   $verifyBots = self::VERIFIABLE_BOTS,
        public array   $trustedProxies = self::DEFAULT_TRUSTED_PROXIES,
        public bool    $dnsChecks = true,
        public ?string $externalScheme = null,
    ) {
        foreach ($this->trustedProxies as $proxy) {
            if (!\is_string($proxy) || !IpMatcher::isValidRule($proxy)) {
                throw new \InvalidArgumentException('Invalid entry in BOTLOCK_TRUSTED_PROXIES: ' . \var_export($proxy, true));
            }
        }

        if ($this->externalScheme !== null && !\in_array($this->externalScheme, self::EXTERNAL_SCHEMES, true)) {
            throw new \InvalidArgumentException('Invalid BOTLOCK_EXTERNAL_SCHEME: ' . \var_export($this->externalScheme, true));
        }
    }

    /**
     * @throws \InvalidArgumentException on a malformed BOTLOCK_TRUSTED_PROXIES entry or BOTLOCK_EXTERNAL_SCHEME
     * @throws \JsonException on a malformed JSON list value
     */
    public static function fromEnv(): self
    {
        return new self(
            ignoreIps: self::cleanList(Env::list('IGNORE_IPS')),
            ignoreUserAgents: self::cleanList(Env::list('IGNORE_USER_AGENTS')),
            ignoreUrls: self::cleanList(Env::list('IGNORE_URLS')),
            goodBots: self::cleanList(Env::list('GOOD_BOTS')) ?: self::DEFAULT_GOOD_BOTS,
            verifyBots: self::resolveVerifyBots(Env::list('VERIFY_BOTS')),
            trustedProxies: self::resolveTrustedProxies(Env::list('TRUSTED_PROXIES')),
            dnsChecks: (bool) Env::bool('DNS_CHECKS', true),
            externalScheme: self::resolveExternalScheme(Env::get('EXTERNAL_SCHEME')),
        );
    }

    /**
     * @param mixed $value from the environment; unset, empty or "auto" means "derive per request"
     */
    private static function resolveExternalScheme(mixed $value): ?string
    {
        $value = \strtolower(\trim((string) $value));

        return ($value === '' || $value === 'auto') ? null : $value;
    }

    /**
     * @param string[]|null $names provider names from the environment; null means "not configured"
     * @return array<string,string[]>
     */
    private static function resolveVerifyBots(?array $names): array
    {
        if ($names === null) {
            return self::VERIFIABLE_BOTS;
        }

        $result = [];

        foreach ($names as $name) {
            $name = \strtolower(\trim($name));

            if (isset(self::VERIFIABLE_BOTS[$name])) {
                $result[$name] = self::VERIFIABLE_BOTS[$name];
            }
        }

        return $result;
    }

    /**
     * @param string[]|null $entries from the environment; null means "not configured"
     * @return string[] unset falls back to DEFAULT_TRUSTED_PROXIES, an empty value trusts nobody
     */
    private static function resolveTrustedProxies(?array $entries): array
    {
        if ($entries === null) {
            return self::DEFAULT_TRUSTED_PROXIES;
        }

        return self::cleanList($entries);
    }

    /**
     * @return string[] trimmed, de-duplicated, without empty entries
     */
    private static function cleanList(?array $values): array
    {
        if (!$values) {
            return [];
        }

        return \array_values(\array_filter(\array_unique(\array_map('trim', $values)), static fn(string $v): bool => $v !== ''));
    }
}
