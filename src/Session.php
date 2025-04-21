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
        if (!$sub = getUserFingerprint())
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
}