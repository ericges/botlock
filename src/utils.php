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

function abort(int $errorCode): never
{
    http_response_code($errorCode);
    echo match ($errorCode) {
        400 => '400 Bad Request',
        401 => '401 Unauthorized',
        403 => '403 Forbidden',
        404 => '404 Not Found',
        405 => '405 Method Not Allowed',
        500 => '500 Internal Server Error',
        default => "$errorCode An error occurred",
    };
    exit;
}

function sendJson(int $statusCode, array $data): never
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
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

function getUserFingerprint(): string
{
    // 1. collect IPs
    $sources = [
        $_SERVER['HTTP_CLIENT_IP']        ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR']  ?? '',
        $_SERVER['REMOTE_ADDR']           ?? '',
    ];

    // 2. extract valid IPs
    $ips = [];
    foreach ($sources as $entry) {
        foreach (explode(',', $entry) as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }
    }

    // 3. remove duplicates and sort
    $ips = array_unique($ips);
    sort($ips, SORT_STRING);

    // 4. add user agent
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // 5. create hash
    $payload = $userAgent . '|' . implode(',', $ips);
    return hash('sha256', $payload);
}
