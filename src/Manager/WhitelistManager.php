<?php

namespace GES\Botlock\Manager;

use GES\Botlock\Http\Request;

readonly class WhitelistManager
{
    public function __construct(private ConfigManager $config) {}

    public function isRequestWhitelisted(Request $request): bool
    {
        if ($request->context->clientIp && $this->isIpIgnored($request->context->clientIp)) {
            return true;
        }

        $userAgent = $request->getHeader('User-Agent');
        if ($userAgent && $this->isUserAgentIgnored($userAgent)) {
            return true;
        }

        if ($this->isUrlIgnored($request->getRequestUrl())) {
            return true;
        }

        return false;
    }

    public function isIpIgnored(string $ip): bool
    {
        $ignoreIps = $this->config->getIgnoreIps();

        return $ip && $ignoreIps && \in_array($ip, $ignoreIps);
    }

    public function isUserAgentIgnored(string $userAgent): bool
    {
        if (!$ignoreUserAgents = $this->config->getIgnoreUserAgents()) {
            return false;
        }

        foreach ($ignoreUserAgents as $ignoredUaStr)
        {
            $ignoredUaStr = \strtolower(\trim($ignoredUaStr));

            if (\str_contains($userAgent, $ignoredUaStr)) {
                return true;
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
            if ($w !== '' && \str_starts_with($url, $w)) {
                return true;
            }
        }

        return false;
    }
}