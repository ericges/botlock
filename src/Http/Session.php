<?php

namespace GES\Botlock\Http;

use GES\Botlock\Config;
use GES\Botlock\JWT;
use function GES\Botlock\getReliableClientIp;

class Session
{
    public final const COOKIE_NAME = 'BOTLOCKSESS';

    private string $sub;
    private array $data = [];
    private bool $commit = false;

    public function __construct(
        private readonly Config  $config,
        private readonly Request $request,
    ) {
        if (!$sub = $this->generateSubject())
        {
            throw new \RuntimeException('Invalid request');
        }

        if ($this->request->hasCookie(self::COOKIE_NAME))
        {
            $payload = JWT::tryGetPayload(
                $this->request->getCookie(self::COOKIE_NAME),
                $this->config->getSecret(),
                $sub,
                $this->request->getUrlWithoutParameters(),
            );

            if ($payload)
            {
                $this->sub = $sub;
                $this->data = $payload['data'] ?? [];
            }
        }

        if (!isset($this->sub))
        {
            $this->sub = $sub;
            $this->data = [];
        }
    }

    public function getSub(): string
    {
        return $this->sub;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): static
    {
        $this->data = [];

        return $this;
    }

    public function commit(bool $commit = true): static
    {
        $this->commit = $commit;

        return $this;
    }

    public function isCommited(): bool
    {
        return $this->commit;
    }

    public function createCookie(): Cookie
    {
        $payload = [
            'sub' => $this->sub,
            'data' => $this->data,
        ];

        $jwt = JWT::create(
            payload: $payload,
            secret: $this->config->getSecret(),
            issuer: $this->request->getUrlWithoutParameters(),
            ttl: $this->config->getExpire(),
        );

        return new Cookie(
            name: self::COOKIE_NAME,
            value: $jwt,
            expires: 0,
            path: '/',
            domain: $this->request->getHost(),
            secure: $this->request->isSecure(),
            httpOnly: true,
            sameSite: 'Strict'
        );
    }

    private function generateSubject(): ?string
    {
        $payloadParts = [];

        // --- IP ADDRESSES ---
        $sources = [
            getReliableClientIp($this->config->getTrustedProxies()) ?? '',
            $this->request->getHeader('Client-Ip', ''),
            $this->request->getHeader('X-Forwarded-For', ''),
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
        if ($userAgent = \trim($this->request->getHeader('User-Agent', ''))) {
            $userAgent = \strtr(\strtolower($userAgent), [' ' => '_']);
            $userAgent = \preg_replace('/[^a-z0-9_\-.]/', '', $userAgent);
            if ($userAgent) {
                $payloadParts[] = 'ua=' . $userAgent;
            }
        }

        // --- ACCEPT LANGUAGE ---
        if ($acceptLanguage = self::filterHeader(\trim($this->request->getHeader('Accept-Language', '')))) {
            $payloadParts[] = 'al=' . $acceptLanguage;
        }

        // --- ACCEPT HEADER ---
        // can't be used as fetch alters the header
        // if ($accept = filterHeader(\trim($_SERVER['HTTP_ACCEPT'] ?? ''))) {
        //     $payloadParts[] = 'ac=' . $accept;
        // }

        // --- ACCEPT ENCODING ---
        if ($acceptEncoding = self::filterHeader(\trim($this->request->getHeader('Accept-Encoding', '')))) {
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