<?php

namespace GES\Botlock;

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
