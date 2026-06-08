<?php

namespace GES\Botlock\Http;

use GES\Botlock\Manager\ConfigManager;
use GES\Botlock\JWT;

class Session
{
    public final const COOKIE_NAME = 'BOTLOCKSESS';

    private string $sub;
    private array $data = [];
    private bool $commit = false;

    public function __construct(
        private readonly ConfigManager $config,
        private readonly Request       $request,
    ) {
        if (!$sub = $this->request->fingerprint)
        {
            throw new \RuntimeException('Invalid request');
        }

        if ($this->request->hasCookie(self::COOKIE_NAME))
        {
            $payload = JWT::tryGetPayload(
                $this->request->getCookie(self::COOKIE_NAME),
                $this->config->getSecret(),
                $sub,
                $this->request->getOrigin(),
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
            issuer: $this->request->getOrigin(),
            ttl: $this->config->getExpire(),
        );

        return new Cookie(
            name: self::COOKIE_NAME,
            value: $jwt,
            expires: 0,
            path: '/',
            domain: null,
            secure: $this->request->isSecure(),
            httpOnly: true,
            sameSite: 'Strict',
        );
    }

    public function createLegacyCookieRemoval(): Cookie
    {
        return new Cookie(
            name: self::COOKIE_NAME,
            value: '',
            expires: 1,
            path: '/',
            domain: $this->request->getHost(),
            secure: $this->request->isSecure(),
            httpOnly: true,
            sameSite: 'Strict',
        );
    }
}