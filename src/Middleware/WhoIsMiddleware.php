<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Http\IpMatcher;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

final readonly class WhoIsMiddleware implements MiddlewareInterface
{
    /** Response header set when a forwarding header was present but not trusted. */
    public const WARNING_HEADER = 'Botlock-Warning';

    /**
     * Headers a proxy may use to forward the original client address, in order of preference.
     */
    private const FORWARDING_HEADERS = [
        // 'Cf-Connecting-Ip', // Cloudflare (currently not supported, needs config option)
        'X-Forwarded-For',  // Default, can be a list (client, proxy1, proxy2)
        'X-Real-Ip',        // Oftentimes used by Nginx
        'Client-Ip',        // Used by older proxies
        // 'Forwarded',     // Newer standard (RFC 7239), needs complex parsing
    ];

    public function __construct(private DetectionConfig $config) {}

    /**
     * @throws \Exception
     */
    public function process(Request $request, callable $next): Response
    {
        $remoteAddr = $request->getServerParam('REMOTE_ADDR');

        if (!\is_string($remoteAddr) || \filter_var($remoteAddr, \FILTER_VALIDATE_IP) === false) {
            $remoteAddr = null;
        }

        $trustedPeer = $remoteAddr !== null && IpMatcher::matchesAny($remoteAddr, $this->config->trustedProxies);

        if ($ip = $this->getReliableClientIp($request, $remoteAddr, $trustedPeer)) {
            $request->context->clientIp = $ip;
        }

        if (!$fingerprint = $this->fingerprint($request)) {
            throw new \Exception('Cannot create fingerprint');
        }

        $request->context->fingerprint = $fingerprint;

        $response = $next($request);

        if (!$trustedPeer && ($header = $this->firstForwardingHeader($request)) !== null) {
            $response = $response->withHeader(self::WARNING_HEADER, $this->ignoredHeaderWarning($header, $remoteAddr));
        }

        return $response;
    }

    /**
     * Determines the client’s most reliable IP address.
     *
     * Forwarding headers (FORWARDING_HEADERS) are only consulted when the direct
     * peer (REMOTE_ADDR) is a trusted proxy, i.e. matches one of the addresses or
     * CIDR ranges in BOTLOCK_TRUSTED_PROXIES. With no trusted proxies configured
     * the headers are never consulted, because any client can spoof them; the
     * ignored header is reported via the Botlock-Warning response header.
     * Otherwise REMOTE_ADDR itself is used.
     *
     * @param string|null $remoteAddr      REMOTE_ADDR, already validated as an IP, or null if missing/malformed
     * @param bool        $trustedPeer     Whether $remoteAddr is a trusted proxy
     * @param bool        $allowPrivateIPs Whether private IP addresses (RFC 1918) are allowed
     *                                     as a result. Defaults to false to prefer public IPs.
     * @return string|null                 The determined IP address, or null if none was valid.
     */
    private function getReliableClientIp(Request $request, ?string $remoteAddr, bool $trustedPeer, bool $allowPrivateIPs = false): ?string
    {
        $clientIp = null;

        if ($trustedPeer)
        {
            foreach (self::FORWARDING_HEADERS as $header)
            {
                if (!$value = $request->getHeader($header)) {
                    continue; // Header not set, skip
                }

                // Some headers can contain multiple IPs (comma-separated)
                // The first one *should* be the client IP
                $ips = \explode(',', $value);
                $potentialIp = \trim(\reset($ips));

                if (self::isValidIp($potentialIp, $allowPrivateIPs))
                {
                    $clientIp = $potentialIp;
                    break;
                }
            }
        }

        // Fallback: If no valid IP was found in the headers, use REMOTE_ADDR, but validate it
        if ($clientIp === null && self::isValidIp($remoteAddr, $allowPrivateIPs)) {
            $clientIp = $remoteAddr;
        }

        return $clientIp;
    }

    private function firstForwardingHeader(Request $request): ?string
    {
        foreach (self::FORWARDING_HEADERS as $header) {
            if ($request->getHeader($header)) {
                return $header;
            }
        }

        return null;
    }

    private function ignoredHeaderWarning(string $header, ?string $remoteAddr): string
    {
        $reason = match (true) {
            $remoteAddr === null => 'remote address is not a valid IP',
            empty($this->config->trustedProxies) => 'forwarding headers disabled (BOTLOCK_TRUSTED_PROXIES is empty)',
            default => "peer $remoteAddr is not a trusted proxy",
        };

        return "forwarding header $header ignored: $reason";
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
        $ip = $request->context->clientIp ?? $request->getServerParam('REMOTE_ADDR', '');
        if (\filter_var($ip, \FILTER_VALIDATE_IP)) {
            $payloadParts[] = 'ip=' . $ip;
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
}
