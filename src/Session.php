<?php

namespace GES\Botlock;

class Session
{
    public final const COOKIE_NAME = 'BOTLOCKSESS';

    private string $id;
    private array $data = [];

    public function __construct(
        private readonly Config $config,
        private readonly JWT $jwt,
    ) {
        $id = null;

        if (isset($_COOKIE[self::COOKIE_NAME]))
        {
            $payload = $this->jwt->tryGetPayload($_COOKIE[self::COOKIE_NAME], $this->config->getSecret());

            if ($payload)
            {
                $id = $payload['sub'] ?? null;
                $this->data = $payload['data'] ?? [];
            }
        }

        if (isset($id))
        {
            $this->id = $id;
        }
        else
        {
            $this->id = randStr(64);
            $this->data = [];
        }
    }

    public function getId(): string
    {
        return $this->id;
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
            'sub' => $this->id,
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