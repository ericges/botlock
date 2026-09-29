<?php declare(strict_types=1);

namespace GES\Botlock\Crawler;

/**
 * Verifies crawlers through reverse and forward DNS lookups: the client IP
 * has to resolve to a host of the provider, and that host back to the IP,
 * the check Google and Microsoft document for their crawlers.
 */
final readonly class DnsCrawlerVerifier implements CrawlerVerifier
{
    /** Host name suffixes of each provider's crawlers, leading dot included. */
    private const HOSTS = [
        'google' => ['.google.com', '.googlebot.com'],
        'bing' => ['.search.msn.com'],
    ];

    private \Closure $reverse;
    private \Closure $forward;

    /**
     * @param \Closure|null $reverse IP → host name or false; defaults to gethostbyaddr()
     * @param \Closure|null $forward host name → list of dns_get_record() A/AAAA records or false
     */
    public function __construct(?\Closure $reverse = null, ?\Closure $forward = null)
    {
        $this->reverse = $reverse ?? static fn(string $ip): string|false => @\gethostbyaddr($ip);
        $this->forward = $forward ?? static fn(string $host): array|false => @\dns_get_record($host, \DNS_A + \DNS_AAAA);
    }

    public function verify(string $provider, string $ip): CrawlerVerification
    {
        if (!isset(self::HOSTS[$provider])) {
            return CrawlerVerification::Unverified;
        }

        return $this->resolvesBothWays($ip, self::HOSTS[$provider])
            ? CrawlerVerification::Verified
            : CrawlerVerification::Failed;
    }

    /**
     * @param list<string> $suffixes
     */
    private function resolvesBothWays(string $ip, array $suffixes): bool
    {
        $host = ($this->reverse)($ip);
        if (!\is_string($host) || $host === '') {
            return false;
        }

        $host = \strtolower($host);
        $matches = \array_filter($suffixes, static fn(string $suffix): bool => \str_ends_with($host, $suffix));
        if ($matches === []) {
            return false;
        }

        $records = ($this->forward)($host);
        if (!\is_array($records) || ($packed = @\inet_pton($ip)) === false) {
            return false;
        }

        foreach ($records as $record) {
            $resolved = $record['ip'] ?? $record['ipv6'] ?? null;

            if (\is_string($resolved) && @\inet_pton($resolved) === $packed) {
                return true;
            }
        }

        return false;
    }
}
