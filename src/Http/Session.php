<?php

namespace GES\Botlock\Http;

use GES\Botlock\JWT;

class Session
{
    public final const COOKIE_NAME = 'BOTLOCKSESS';

    private array $data = [];
    private bool $commit = false;

    /**
     * @param string      $secret    Secret used to sign the session JWT
     * @param int         $ttl       Session lifetime in seconds
     * @param string      $sub       Subject the session is bound to (client fingerprint)
     * @param string      $origin    Request origin, used as JWT issuer
     * @param string      $host      Request host, used for the legacy cookie removal
     * @param bool        $secure    Whether the request is HTTPS (cookie Secure flag)
     * @param string|null $cookieJwt Existing session cookie value, if any
     */
    public function __construct(
        private readonly string $secret,
        private readonly int    $ttl,
        private readonly string $sub,
        private readonly string $origin,
        private readonly string $host,
        private readonly bool   $secure,
        ?string                 $cookieJwt = null,
    ) {
        if ($this->sub === '') {
            throw new \InvalidArgumentException('Session subject must not be empty');
        }

        if ($cookieJwt !== null && $cookieJwt !== '')
        {
            $payload = JWT::tryGetPayload($cookieJwt, $this->secret, $this->sub, $this->origin);

            if ($payload) {
                $this->data = \is_array($payload['data'] ?? null) ? $payload['data'] : [];
            }
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
            secret: $this->secret,
            issuer: $this->origin,
            ttl: $this->ttl,
        );

        return new Cookie(
            name: self::COOKIE_NAME,
            value: $jwt,
            expires: 0,
            path: '/',
            domain: null,
            secure: $this->secure,
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
            domain: $this->host,
            secure: $this->secure,
            httpOnly: true,
            sameSite: 'Strict',
        );
    }
}
