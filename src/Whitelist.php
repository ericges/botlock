<?php

namespace GES\Botlock;

use GES\Botlock\Http\Request;

class Whitelist
{
    private string $ip;

    public function __construct(private readonly Config $config) {}

    public function getIp(): string
    {
        if (!isset($this->ip)) {
            $this->ip = getReliableClientIp($this->config->getTrustedProxies()) ?? '';
        }

        return $this->ip;
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

    public function isRequestWhitelisted(Request $request): bool
    {
        if ($this->getIp() && $this->isIpIgnored($this->getIp())) {
            return true;
        }

        $userAgent = $request->getHeader('User-Agent');
        if ($userAgent && $this->isUserAgentIgnored($userAgent, $this->getIp() ?: null)) {
            return true;
        }

        if ($this->config->getIgnoreUrls() && $this->isUrlIgnored($request->getRequestUrl())) {
            return true;
        }

        return false;
    }
}