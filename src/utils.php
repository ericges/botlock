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
    echo [
        400 => '400 Bad Request',
        401 => '401 Unauthorized',
        403 => '403 Forbidden',
        404 => '404 Not Found',
        405 => '405 Method Not Allowed',
        500 => '500 Internal Server Error',
    ][$errorCode] ?? ($errorCode . ' An error occurred');
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
