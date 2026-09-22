<?php

namespace GES\Botlock\Manager;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Http\Request;

readonly class WhitelistManager
{
    public function __construct(private DetectionConfig $config) {}

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
        $ignoreIps = $this->config->ignoreIps;

        return $ip && $ignoreIps && \in_array($ip, $ignoreIps);
    }

    public function isUserAgentIgnored(string $userAgent): bool
    {
        if (!$ignoreUserAgents = $this->config->ignoreUserAgents) {
            return false;
        }

        $userAgent = \strtolower($userAgent);

        foreach ($ignoreUserAgents as $ignoredUaStr)
        {
            $ignoredUaStr = \strtolower(\trim($ignoredUaStr));

            if ($ignoredUaStr !== '' && \str_contains($userAgent, $ignoredUaStr)) {
                return true;
            }
        }

        return false;
    }

    public function isUrlIgnored(string $url): bool
    {
        if (!$ignoreUrls = $this->config->ignoreUrls) {
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