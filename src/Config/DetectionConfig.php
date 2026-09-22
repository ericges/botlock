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
    ];

    /**
     * @param string[]                $ignoreIps        Exact client IPs that bypass botlock
     * @param string[]                $ignoreUserAgents User-Agent substrings that bypass botlock (case-insensitive)
     * @param string[]                $ignoreUrls       Absolute URL prefixes that bypass botlock
     * @param string[]                $goodBots         CrawlerDetect names treated as good bots
     * @param array<string,string[]>  $verifyBots       Subset of VERIFIABLE_BOTS to verify via DNS
     * @param string[]                $trustedProxies   Proxy IPs or CIDR ranges whose forwarding headers are trusted; empty trusts none
     * @param bool                    $dnsChecks        Master switch for DNS verification
     *
     * @throws \InvalidArgumentException on a malformed trusted proxy entry
     */
    public function __construct(
        public array $ignoreIps = [],
        public array $ignoreUserAgents = [],
        public array $ignoreUrls = [],
        public array $goodBots = self::DEFAULT_GOOD_BOTS,
        public array $verifyBots = self::VERIFIABLE_BOTS,
        public array $trustedProxies = [],
        public bool  $dnsChecks = true,
    ) {
        foreach ($this->trustedProxies as $proxy) {
            if (!\is_string($proxy) || !IpMatcher::isValidRule($proxy)) {
                throw new \InvalidArgumentException('Invalid entry in BOTLOCK_TRUSTED_PROXIES: ' . \var_export($proxy, true));
            }
        }
    }

    /**
     * @throws \InvalidArgumentException on a malformed BOTLOCK_TRUSTED_PROXIES entry
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
            trustedProxies: self::cleanList(Env::list('TRUSTED_PROXIES')),
            dnsChecks: (bool) Env::bool('DNS_CHECKS', true),
        );
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
