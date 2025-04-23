<?php

namespace GES\Botlock;

class VerifyBot
{
    public static function google(string $ip): bool
    {
        $hostname = @\gethostbyaddr($ip);
        if (!$hostname) {
            return false;
        }

        $hostname = \strtolower($hostname);
        if (!\str_ends_with($hostname, '.google.com') && !\str_ends_with($hostname, '.googlebot.com')) {
            return false;
        }

        $ips = @\dns_get_record($hostname, \DNS_A + \DNS_AAAA);
        if (!$ips) {
            return false;
        }

        $foundOriginalIp = false;
        foreach ($ips as $record) {
            $resolvedIp = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($resolvedIp === $ip) {
                $foundOriginalIp = true;
                break;
            }
        }

        if (!$foundOriginalIp) {
            return false;
        }

        return true;
    }
}