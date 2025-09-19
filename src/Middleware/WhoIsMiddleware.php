<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Manager\ConfigManager;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

readonly class WhoIsMiddleware implements MiddlewareInterface
{
    public function __construct(private ConfigManager $config) {}

    /**
     * @throws \Exception
     */
    public function process(Request $request, callable $next): Response
    {
        $trustedProxies = $this->normalizeTrustedProxies($this->config->getTrustedProxies());

        if ($ip = $this->getReliableClientIp($request, $trustedProxies)) {
            $request->bind('clientIp', $ip);
        }

        if (!$fingerprint = $this->fingerprint($request)) {
            throw new \Exception('Cannot create fingerprint');
        }

        $request->bind('fingerprint', $fingerprint);

        return $next($request);
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
    private function getReliableClientIp(Request $request, array $trustedProxies = [], bool $allowPrivateIPs = false): ?string
    {
        $remoteAddr = $request->getServerParam('REMOTE_ADDR');

        if (!self::isValidIp($remoteAddr, true)) {
            $remoteAddr = null;
        }

        // List of headers to check for the client IP
        $headerChecks = [
            // 'Cf-Connecting-Ip', // Cloudflare // todo: create config option
            'X-Forwarded-For',  // Default, can be a list (client, proxy1, proxy2)
            'X-Real-Ip',        // Oftentimes used by Nginx
            'Client-Ip',        // Used by older proxies
            // 'Forwarded',     // Newer standard (RFC 7239), needs complex parsing
        ];

        $isConnectedViaTrustedProxy = $remoteAddr
            && !empty($trustedProxies)
            && $this->isTrustedProxy($remoteAddr, $trustedProxies);

        $clientIp = null;

        // Only check the headers if the request comes from a trusted proxy
        if ($isConnectedViaTrustedProxy)
        {
            foreach ($headerChecks as $header)
            {
                if (!$value = $request->getHeader($header)) {
                    continue; // Header not set, skip
                }

                $ips = [];

                foreach (\explode(',', $value) as $forwardedIp) {
                    $forwardedIp = \trim($forwardedIp);

                    if ($forwardedIp === '') {
                        continue;
                    }

                    if (!self::isValidIp($forwardedIp, true)) {
                        $ips = [];
                        break; // invalid entry invalidates entire header
                    }

                    $ips[] = $forwardedIp;
                }

                if (empty($ips)) {
                    continue;
                }

                $potentialIp = \array_shift($ips);

                if (!self::isValidIp($potentialIp, $allowPrivateIPs)) {
                    continue;
                }

                $forwardChainTrusted = true;

                foreach ($ips as $proxyIp) {
                    if (!$this->isTrustedProxy($proxyIp, $trustedProxies)) {
                        $forwardChainTrusted = false;
                        break;
                    }
                }

                if (!$forwardChainTrusted) {
                    continue;
                }

                $clientIp = $potentialIp;
                break;
            }
        }

        // Fallback: If no valid IP was found in the headers, use REMOTE_ADDR, but validate it
        if ($clientIp === null && self::isValidIp($remoteAddr, $allowPrivateIPs)) {
            $clientIp = $remoteAddr;
        }

        // final validation; if the IP is not valid, return null (should not happen, but just in case)
        if ($clientIp !== null && !self::isValidIp($clientIp, $allowPrivateIPs)) {
            return null;
        }

        return $clientIp;
    }

    private static function isValidIp(?string $ip, bool $allowPrivate = false): bool
    {
        if (empty($ip)) {
            return false;
        }

        $flags = \FILTER_FLAG_IPV4 | \FILTER_FLAG_IPV6;

        if (!$allowPrivate) {
            // Exclude private and reserved IP ranges (RFC 1918 for IPv4, fc00::/7 for IPv6)
            $flags |= \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE;
        }

        return \filter_var($ip, \FILTER_VALIDATE_IP, $flags) !== false;
    }

    private function fingerprint(Request $request): ?string
    {
        $payloadParts = [];

        // --- IP ADDRESSES ---
        $sources = [
            $request->clientIp ?? '',
            $request->getServerParam('REMOTE_ADDR', ''),
        ];

        $ips = [];
        foreach ($sources as $entry) {
            foreach (\explode(',', $entry) as $ip) {
                $ip = \trim($ip);
                if (\filter_var($ip, \FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }

        if (!empty($ips)) {
            $ips = \array_unique($ips);
            \sort($ips, SORT_STRING);
            $payloadParts[] = 'ip=' . \implode(',', $ips);
        }

        // --- USER AGENT ---
        if ($userAgent = \trim($request->getHeader('User-Agent', ''))) {
            $userAgent = \strtr(\strtolower($userAgent), [' ' => '_']);
            $userAgent = \preg_replace('/[^a-z0-9_\-.]/', '', $userAgent);
            if ($userAgent) {
                $payloadParts[] = 'ua=' . $userAgent;
            }
        }

        // --- ACCEPT LANGUAGE ---
        if ($acceptLanguage = self::filterHeader(\trim($request->getHeader('Accept-Language', '')))) {
            $payloadParts[] = 'al=' . $acceptLanguage;
        }

        // --- ACCEPT HEADER ---
        // can't be used as fetch alters the header
        // if ($accept = filterHeader(\trim($_SERVER['HTTP_ACCEPT'] ?? ''))) {
        //     $payloadParts[] = 'ac=' . $accept;
        // }

        // --- ACCEPT ENCODING ---
        if ($acceptEncoding = self::filterHeader(\trim($request->getHeader('Accept-Encoding', '')))) {
            $payloadParts[] = 'ae=' . $acceptEncoding;
        }

        // Assemble payload
        if (empty($payloadParts)) {
            return null;
        }

        // sort payload parts to make order of parts deterministic
        \sort($payloadParts, \SORT_STRING);

        $payload = \implode('|', $payloadParts);

        return \hash('sha256', $payload);
    }

    private static function filterHeader(?string $str): ?string
    {
        if (!$str) {
            return null;
        }

        $arr = [];

        foreach (\explode(',', $str) as $part) {
            if ($part = \trim(\explode(';', $part)[0] ?? '')) {
                $arr[] = \trim($part);
            }
        }

        $arr = \array_unique($arr);
        \sort($arr, \SORT_STRING);

        return empty($arr) ? null : \implode(',', \array_slice($arr, 0, 5));
    }

    /**
     * @param array<string> $trustedProxies
     * @return array<string>
     */
    private function normalizeTrustedProxies(array $trustedProxies): array
    {
        $normalized = [];

        foreach ($trustedProxies as $proxy) {
            $proxy = \trim((string) $proxy);

            if ($proxy === '') {
                continue;
            }

            $normalized[$proxy] = true;
        }

        return \array_keys($normalized);
    }

    /**
     * @param array<string> $trustedProxies
     */
    private function isTrustedProxy(?string $ip, array $trustedProxies): bool
    {
        if (!$ip) {
            return false;
        }

        foreach ($trustedProxies as $trusted) {
            if ($trusted === $ip) {
                return true;
            }

            if (\str_contains($trusted, '/') && self::ipMatchesCidr($ip, $trusted)) {
                return true;
            }
        }

        return false;
    }

    private static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = \array_pad(\explode('/', $cidr, 2), 2, null);

        if ($mask === null) {
            return false;
        }

        $mask = (int) $mask;

        if (\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) && \filter_var($subnet, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            if ($mask < 0 || $mask > 32) {
                return false;
            }

            $ipLong = \ip2long($ip);
            $subnetLong = \ip2long($subnet);

            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            $maskLong = -1 << (32 - $mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        if (\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) && \filter_var($subnet, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            if ($mask < 0 || $mask > 128) {
                return false;
            }

            $ipBin = \inet_pton($ip);
            $subnetBin = \inet_pton($subnet);

            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            $fullBytes = intdiv($mask, 8);
            $remainingBits = $mask % 8;

            if ($fullBytes > 0 && \strncmp($ipBin, $subnetBin, $fullBytes) !== 0) {
                return false;
            }

            if ($remainingBits === 0) {
                return true;
            }

            $maskByte = (0xFF << (8 - $remainingBits)) & 0xFF;

            return (\ord($ipBin[$fullBytes]) & $maskByte) === (\ord($subnetBin[$fullBytes]) & $maskByte);
        }

        return false;
    }
}
