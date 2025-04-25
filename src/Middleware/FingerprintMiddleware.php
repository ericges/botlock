<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Config;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use function GES\Botlock\getReliableClientIp;

readonly class FingerprintMiddleware implements MiddlewareInterface
{
    public function __construct(private Config $config) {}

    /**
     * @throws \Exception
     */
    public function process(Request $request, callable $next): Response
    {
        if (!$fingerprint = $this->fingerprint($request)) {
            throw new \Exception('Cannot create fingerprint');
        }

        $request->bind('fingerprint', $fingerprint);

        return $next($request);
    }

    private function fingerprint(Request $request): ?string
    {
        $payloadParts = [];

        // --- IP ADDRESSES ---
        $sources = [
            getReliableClientIp($this->config->getTrustedProxies()) ?? '',
            $request->getHeader('Client-Ip', ''),
            $request->getHeader('X-Forwarded-For', ''),
            $_SERVER['REMOTE_ADDR'] ?? '',
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
}