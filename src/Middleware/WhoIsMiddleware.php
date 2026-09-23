<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Http\IpMatcher;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

/**
 * Resolves the client IP, the external scheme and the fingerprint of a request.
 *
 * Forwarding headers are only consulted when the direct peer (REMOTE_ADDR) is a
 * trusted proxy, i.e. matches an address or CIDR range in BOTLOCK_TRUSTED_PROXIES.
 * X-Forwarded-For is read from the right: trailing entries that are trusted
 * proxies are skipped and the first remaining entry is the client, so entries a
 * client prepends itself are never reached. When X-Forwarded-For is present it is
 * authoritative; X-Real-Ip and Client-Ip are only used when a proxy sets one of
 * them instead. X-Forwarded-Proto from a trusted proxy (or BOTLOCK_EXTERNAL_SCHEME,
 * which wins) sets the scheme the visitor actually used, so a TLS-terminating
 * proxy still yields a Secure session cookie and an https issuer. Whenever a
 * forwarding header is present but cannot be used, the connecting address or
 * scheme is used and the reason is reported in the Botlock-Warning response header.
 */
final readonly class WhoIsMiddleware implements MiddlewareInterface
{
    /** Response header set when a forwarding header was present but could not be used. */
    public const WARNING_HEADER = 'Botlock-Warning';

    /** Comma-separated chain (client, proxy1, proxy2, ...) that appending proxies extend on the right. */
    private const CHAIN_HEADER = 'X-Forwarded-For';

    /** Scheme of the client-facing hop; appending proxies extend it on the right like X-Forwarded-For. */
    private const PROTO_HEADER = 'X-Forwarded-Proto';

    /** Single-valued headers set by a proxy that does not use X-Forwarded-For, in order of preference. */
    private const SINGLE_VALUE_HEADERS = [
        // 'Cf-Connecting-Ip', // Cloudflare (currently not supported, needs config option)
        'X-Real-Ip',        // Oftentimes used by Nginx
        'Client-Ip',        // Used by older proxies
        // 'Forwarded',     // Newer standard (RFC 7239), needs complex parsing
    ];

    /** Longer X-Forwarded-For chains are treated as malformed. */
    private const MAX_CHAIN_LENGTH = 32;

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

        [$clientIp, $ipWarning] = $this->resolveClientIp($request, $remoteAddr, $trustedPeer);
        $schemeWarning = $this->resolveScheme($request, $remoteAddr, $trustedPeer);
        $warnings = \array_filter([$ipWarning, $schemeWarning]);

        if ($clientIp !== null) {
            $request->context->clientIp = $clientIp;
        }

        if (!$fingerprint = $this->fingerprint($request)) {
            throw new \Exception('Cannot create fingerprint');
        }

        $request->context->fingerprint = $fingerprint;

        $response = $next($request);

        if ($warnings) {
            $response = $response->withHeader(self::WARNING_HEADER, \implode('; ', $warnings));
        }

        return $response;
    }

    /**
     * Applies BOTLOCK_EXTERNAL_SCHEME or, failing that, X-Forwarded-Proto from a
     * trusted proxy to the request.
     *
     * @return string|null a Botlock-Warning message when the header was present but ignored
     */
    private function resolveScheme(Request $request, ?string $remoteAddr, bool $trustedPeer): ?string
    {
        if (($forced = $this->config->externalScheme) !== null) {
            $request->setScheme($forced);

            return null;
        }

        if (($header = $request->getHeader(self::PROTO_HEADER)) === null) {
            return null;
        }

        if (!$trustedPeer) {
            return $this->ignoredHeaderWarning(self::PROTO_HEADER, $remoteAddr);
        }

        // Leftmost entry is the client-facing hop; later proxies append their own.
        $scheme = \strtolower(\trim(\explode(',', $header)[0]));

        if (!\in_array($scheme, DetectionConfig::EXTERNAL_SCHEMES, true)) {
            // Not echoed: arbitrary request bytes do not belong in a response header
            return \sprintf('forwarding header %s ignored: malformed value', self::PROTO_HEADER);
        }

        $request->setScheme($scheme);

        return null;
    }

    /**
     * Determines the client’s most reliable public IP address.
     *
     * @param string|null $remoteAddr  REMOTE_ADDR, already validated as an IP, or null if missing/malformed
     * @param bool        $trustedPeer Whether $remoteAddr is a trusted proxy
     * @return array{string|null, string|null} the client IP (falls back to REMOTE_ADDR, null if none is
     *                                         a valid public address) and a Botlock-Warning message or null
     */
    private function resolveClientIp(Request $request, ?string $remoteAddr, bool $trustedPeer): array
    {
        $clientIp = null;
        $warning = null;

        if (!$trustedPeer)
        {
            if (($header = $this->firstForwardingHeader($request)) !== null) {
                $warning = $this->ignoredHeaderWarning($header, $remoteAddr);
            }
        }
        elseif ($chain = $request->getHeader(self::CHAIN_HEADER))
        {
            // Authoritative: an appending proxy always writes this header and a client cannot
            // remove it, whereas a client-sent X-Real-Ip may pass through a proxy untouched.
            [$clientIp, $warning] = $this->fromForwardedChain($chain);
        }
        else
        {
            foreach (self::SINGLE_VALUE_HEADERS as $header)
            {
                if (!$value = $request->getHeader($header)) {
                    continue; // Header not set, skip
                }

                $candidate = self::normalizeEntry($value);

                if ($candidate === null) {
                    $warning = "forwarding header $header ignored: malformed value";
                } elseif (IpMatcher::matchesAny($candidate, $this->config->trustedProxies)) {
                    $warning = "forwarding header $header ignored: $candidate is a trusted proxy";
                } elseif (!self::isValidIp($candidate)) {
                    $warning = "forwarding header $header ignored: $candidate is not a public address";
                } else {
                    $clientIp = $candidate;
                }

                break; // only the first present header is evaluated
            }
        }

        // Fallback: use REMOTE_ADDR, but only if it is a valid public address
        if ($clientIp === null && self::isValidIp($remoteAddr)) {
            $clientIp = $remoteAddr;
        }

        return [$clientIp, $warning];
    }

    /**
     * Walks an X-Forwarded-For chain from the right, skipping trusted proxies.
     *
     * @return array{string|null, string|null} client IP or null, and a warning or null
     */
    private function fromForwardedChain(string $header): array
    {
        $entries = \explode(',', $header);

        if (\count($entries) > self::MAX_CHAIN_LENGTH) {
            return [null, \sprintf('forwarding header %s ignored: more than %d entries', self::CHAIN_HEADER, self::MAX_CHAIN_LENGTH)];
        }

        for ($i = \count($entries) - 1; $i >= 0; $i--)
        {
            $entry = self::normalizeEntry($entries[$i]);

            if ($entry === null) {
                // Not echoed: arbitrary request bytes do not belong in a response header
                return [null, \sprintf('forwarding header %s ignored: malformed entry at position %d', self::CHAIN_HEADER, $i + 1)];
            }

            if (IpMatcher::matchesAny($entry, $this->config->trustedProxies)) {
                continue; // a proxy hop we trust, keep walking left
            }

            if (!self::isValidIp($entry)) {
                return [null, \sprintf('forwarding header %s ignored: hop %s is not a trusted proxy', self::CHAIN_HEADER, $entry)];
            }

            return [$entry, null];
        }

        // Every entry is a trusted proxy (e.g. the proxy's own health check): silently use REMOTE_ADDR
        return [null, null];
    }

    /**
     * Trims a forwarding header entry and strips the two common decorations,
     * "[v6]" / "[v6]:port" and "v4:port". A bare IPv6 address with a port is
     * ambiguous and not supported, nor are zone IDs (fe80::1%eth0).
     *
     * @return string|null the bare IP address, or null if the entry is not a valid IP
     */
    private static function normalizeEntry(string $entry): ?string
    {
        $entry = \trim($entry);

        if (\preg_match('/^\[([0-9A-Fa-f:.]+)\](?::\d{1,5})?$/', $entry, $m)) {
            $entry = $m[1];
        } elseif (\preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/', $entry, $m)) {
            $entry = $m[1];
        }

        return \filter_var($entry, \FILTER_VALIDATE_IP) !== false ? $entry : null;
    }

    private function firstForwardingHeader(Request $request): ?string
    {
        foreach ([self::CHAIN_HEADER, ...self::SINGLE_VALUE_HEADERS] as $header) {
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

    /**
     * Whether $ip is a valid public (non-private, non-reserved) IPv4 or IPv6 address.
     */
    private static function isValidIp(?string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }

        // Exclude private and reserved IP ranges (RFC 1918 for IPv4, fc00::/7 for IPv6)
        $flags = \FILTER_FLAG_IPV4 | \FILTER_FLAG_IPV6 | \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE;

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
