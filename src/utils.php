<?php

namespace GES\Botlock;

function env(string $name, $default = null): mixed
{
    $name = 'BOTLOCK_' . \strtoupper($name);
    $value = \getenv($name);

    if ($value === false) {
        return $default;
    }

    if (is_string($value)) {
        return trim($value);
    }

    return $value;
}

function envArray(string $name): array {
    if (!$value = env($name, '')) {
        return [];
    }
    if (!\is_string($value)) {
        return [];
    }
    if ($value[0] !== '[' || $value[-1] !== ']') {
        return \explode(',', $value);
    }
    $data = \json_decode($value, true) ?: [];
    return \array_values(\array_filter($data, 'is_string'));
}

function isRequestHttps(): bool
{
    return ($_SERVER['HTTPS'] ?? 'off') !== 'off' || (string) ($_SERVER['SERVER_PORT'] ?? '') == "443";
}

function getRequestUrl(): string
{
    $scheme = isRequestHttps() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
    $port = (string) $_SERVER['SERVER_PORT'] ?? null;
    $path = $_SERVER['REQUEST_URI'] ?? '/';

    if ($port && !\in_array($port, ['80', '443'])) {
        $host .= ':' . $port;
    }

    return "$scheme://$host$path";
}

function respond(int $statusCode = 200, ?string $body = null, ?array $headers = null): never
{
    foreach ($headers ?? [] as $header)
    {
        if (\is_array($header) && \count($header) === 2)
        {
            $header = \trim($header[0]) . ': ' . \trim($header[1]);
        }

        if (!\is_string($header)) {
            continue;
        }

        header($header);
    }

    http_response_code($statusCode);

    if ($body !== null) {
        echo $body;
    }

    exit;
}

function abort(int $errorCode, bool $json = false, ?array $headers = null): never
{
    $body = match ($errorCode) {
        400 => '400 Bad Request',
        401 => '401 Unauthorized',
        403 => '403 Forbidden',
        404 => '404 Not Found',
        405 => '405 Method Not Allowed',
        500 => '500 Internal Server Error',
        default => "$errorCode An error occurred",
    };

    $headers = \array_merge([
        $json ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: text/plain; charset=utf-8',
        'Content-Length: ' . \strlen($body),
        ...($headers ?? []),
    ]);

    respond($errorCode, $body, $headers);
}

function sendJson(int $statusCode, array $data, ?array $headers = null): never
{
    $body = \json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);

    if ($body === false) {
        abort(500, true);
    }

    $headers = \array_merge([
        'Content-Type: application/json; charset=utf-8',
        'Content-Length: ' . \strlen($body),
    ], $headers ?? []);

    respond($statusCode, $body, $headers);
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

function filterHeader(?string $str): ?string
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

function getUserFingerprint(): ?string
{
    $payloadParts = [];

    // --- IP ADDRESSES ---
    $sources = [
        $_SERVER['HTTP_CLIENT_IP']        ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR']  ?? '',
        $_SERVER['REMOTE_ADDR']           ?? '',
    ];

    $ips = [];
    foreach ($sources as $entry) {
        foreach (\explode(',', $entry) as $ip) {
            $ip = \trim($ip);
            if (\filter_var($ip, FILTER_VALIDATE_IP)) {
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
    if ($userAgent = \trim($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        $userAgent = \strtr(\strtolower($userAgent), [' ' => '_']);
        $userAgent = \preg_replace('/[^a-z0-9_\-.]/', '', $userAgent);
        if ($userAgent) {
            $payloadParts[] = 'ua=' . $userAgent;
        }
    }

    // --- ACCEPT LANGUAGE ---
    if ($acceptLanguage = filterHeader(\trim($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) {
        $payloadParts[] = 'al=' . $acceptLanguage;
    }

    // --- ACCEPT HEADER ---
    // can't be used as fetch alters the header
    // if ($accept = filterHeader(\trim($_SERVER['HTTP_ACCEPT'] ?? ''))) {
    //     $payloadParts[] = 'ac=' . $accept;
    // }

    // --- ACCEPT ENCODING ---
    if ($acceptEncoding = filterHeader(\trim($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''))) {
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
