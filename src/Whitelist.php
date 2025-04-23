<?php

namespace GES\Botlock;

use Jaybizzle\CrawlerDetect\CrawlerDetect;

class Whitelist
{
    private readonly ?string $ip;
    private readonly string $userAgent;
    private bool $isCrawler;
    private ?string $crawlerMatch = null;

    public function __construct(
        private readonly Config $config,
    ) {
        $this->ip = getReliableClientIp($this->config->getTrustedProxies());
        $this->userAgent = \strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    public function isBot(): bool
    {
        if (!isset($this->isCrawler))
        {
            $crawlerDetect = new CrawlerDetect();
            $this->isCrawler = $crawlerDetect->isCrawler();
            $this->crawlerMatch = $crawlerDetect->getMatches();
        }

        return $this->isCrawler;
    }

    public function isIpIgnored(string $ip): bool
    {
        $ignoreIps = $this->config->getIgnoreIps();

        return $ip && $ignoreIps && \in_array($ip, $ignoreIps);
    }

    public function isUserAgentIgnored(string $userAgent, ?string $ip = null): bool
    {
        if (!$ignoreUserAgents = $this->config->getIgnoreUserAgents()) {
            return false;
        }

        foreach ($ignoreUserAgents as $ignoredUaStr)
        {
            $ignoredUaStr = \strtolower(\trim($ignoredUaStr));

            if (!\str_contains($userAgent, $ignoredUaStr)) {
                continue;
            }

            if (!$ip || !$this->config->getDnsChecks()) {
                return true;
            }

            $mayReturn = match (true) {
                \str_contains($ignoredUaStr, 'google') => VerifyBot::google($ip) ?: null,
                default => true,
            };

            if ($mayReturn !== null) {
                return $mayReturn;
            }
        }

        return false;
    }

    public function isUrlIgnored(string $url): bool
    {
        if (!$ignoreUrls = $this->config->getIgnoreUrls()) {
            return false;
        }

        foreach ($ignoreUrls as $w) {
            $w = \trim($w);
            if (\strlen($w) > 0 && \str_starts_with($url, $w)) {
                return true;
            }
        }

        return false;
    }

    public function isRequestWhitelisted(): bool
    {
        if ($this->ip && $this->isIpIgnored($this->ip)) {
            return true;
        }

        if ($this->userAgent && $this->isUserAgentIgnored($this->userAgent, $this->ip)) {
            return true;
        }

        if ($this->config->getIgnoreUrls() && $this->isUrlIgnored(getRequestUrl())) {
            return true;
        }

        return false;
    }

    public function isGoodBot(): bool
    {
        if (!$this->isBot())
        {
            return false;
        }

        $match = \strtolower($this->crawlerMatch ?? '');
        $goodBots = \array_map(
            static fn($bot): string => \trim(\strtolower((string) $bot)),
            $this->config->getGoodBots(),
        );

        return $match && \array_filter($goodBots, static fn($bot): bool => \str_contains($match, $bot));
    }
}