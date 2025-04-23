<?php

namespace GES\Botlock;

function env(string $name, $default = null): mixed
{
    $name = 'BOTLOCK_' . \strtoupper($name);
    $value = \getenv($name);

    if ($value === false) {
        return $default;
    }

    if (\is_string($value)) {
        return \trim($value);
    }

    return $value;
}

function envArray(string $name, ?array $default = null): ?array
{
    $value = env($name);

    if (!\is_string($value)) {
        return $default;
    }

    if (\strlen($value) < 1) {
        return [];
    }

    if ($value[0] !== '[' || $value[-1] !== ']') {
        return \explode(',', $value);
    }

    $data = \json_decode($value, true) ?: [];

    return \array_values(\array_filter($data, 'is_string'));
}

/**
 * Attempts to determine the client’s most reliable public IP address,
 * even if the request has been routed through proxies.
 *
 * IMPORTANT: Only reliable if the proxies are configured correctly
 * and the headers cannot be spoofed by the client (see notes below).
 *
 * @param array $trustedProxies   A list of IP addresses that are trusted proxies.
 *                                Forwarding headers are only considered if the direct
 *                                connection ($_SERVER['REMOTE_ADDR']) originates from
 *                                one of these proxies. Leave empty to trust all
 *                                proxies (insecure!) or none.
 * @param bool  $allowPrivateIPs  Whether private IP addresses (RFC 1918) are allowed
 *                                as a result. Defaults to false to prefer public IPs.
 * @return string|null            The determined IP address, or null if none was valid.
 */
function getReliableClientIp(array $trustedProxies = [], bool $allowPrivateIPs = false): ?string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

    // List of headers to check for the client IP
    $headerChecks = [
        // 'HTTP_CF_CONNECTING_IP', // Cloudflare // todo: create config option
        'HTTP_X_FORWARDED_FOR',  // Default, can be a list (client, proxy1, proxy2)
        'HTTP_X_REAL_IP',        // Oftentimes used by Nginx
        'HTTP_CLIENT_IP',        // Used by older proxies
        // 'HTTP_FORWARDED',     // Neuer Standard (RFC 7239), komplexeres Parsing nötig
    ];

    $isConnectedViaTrustedProxy = $remoteAddr
        && !empty($trustedProxies)
        && \in_array($remoteAddr, $trustedProxies, true);

    $clientIp = null;

    // Only check the headers if the request comes from a trusted proxy
    // OR if no trusted proxies are configured, trust blindly (not recommended)
    if ($isConnectedViaTrustedProxy || empty($trustedProxies))
    {
        foreach ($headerChecks as $header) {
            if (empty($_SERVER[$header])) {
                continue; // Header not set, skip
            }

            // Some headers can contain multiple IPs (comma-separated)
            // The first one *should* be the client IP
            $ips = \explode(',', $_SERVER[$header]);
            $potentialIp = \trim(\reset($ips));

            if (isValidIp($potentialIp, $allowPrivateIPs))
            {
                $clientIp = $potentialIp;
                break;
            }
        }
    }

    // Fallback: If no valid IP was found in the headers, use REMOTE_ADDR, but validate it
    if ($clientIp === null && isValidIp($remoteAddr, $allowPrivateIPs)) {
        $clientIp = $remoteAddr;
    }

    // final validation; if the IP is not valid, return null (should not happen, but just in case)
    if ($clientIp !== null && !isValidIp($clientIp, $allowPrivateIPs)) {
        return null;
    }

    return $clientIp;
}

function isValidIp(?string $ip, bool $allowPrivate = false): bool
{
    if (empty($ip)) {
        return false;
    }

    $flags = \FILTER_FLAG_IPV4 | \FILTER_FLAG_IPV6;

    if (!$allowPrivate) {
        // Exclude private and reserved IP ranges (RFC 1918 for IPv4, fc00::/7 for IPv6)
        $flags |= FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    }

    return \filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
}

function randStr($length, ?string $chars = null): string
{
    $chars ??= '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $bound = \strlen($chars) - 1;
    $str = '';

    for ($i = 0; $i < $length; $i++) {
        $str .= $chars[\random_int(0, $bound)];
    }

    return $str;
}

function base64url_decode(string $data): string|false
{
    $b64 = \strtr($data, '-_', '+/');

    if ($pad = \strlen($b64) % 4) {
        $b64 .= \str_repeat('=', 4 - $pad);
    }

    return base64_decode($b64);
}

function base64url_encode(string $data): string
{
    $b64 = \strtr(\base64_encode($data), '+/', '-_');
    return rtrim($b64, '=');
}
