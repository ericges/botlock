<?php

namespace GES\Botlock;

class Session
{
    public final const COOKIE_NAME = 'BOTLOCKSESS';

    private string $sub;
    private array $data = [];

    public function __construct(
        private readonly Config $config,
        private readonly JWT $jwt,
    ) {
        if (!$sub = $this->generateSubject())
        {
            throw new \RuntimeException('Invalid request');
        }

        if (isset($_COOKIE[self::COOKIE_NAME]))
        {
            $payload = $this->jwt->tryGetPayload($_COOKIE[self::COOKIE_NAME], $this->config->getSecret(), $sub);

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

    public function clear(): void
    {
        $this->data = [];
    }

    public function write(): void
    {
        $payload = [
            'sub' => $this->sub,
            'data' => $this->data,
        ];

        $jwt = $this->jwt->create($payload, $this->config->getSecret(), $this->config->getExpire());

        \setcookie(self::COOKIE_NAME, $jwt, [
            'expires' => 0,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            'secure' => isRequestHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function generateSubject(): ?string
    {
        $payloadParts = [];

        // --- IP ADDRESSES ---
        $sources = [
            getReliableClientIp($this->config->getTrustedProxies()) ?? '',
            $_SERVER['HTTP_CLIENT_IP']        ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR']  ?? '',
            $_SERVER['REMOTE_ADDR']           ?? '',
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
}