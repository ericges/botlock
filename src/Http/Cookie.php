<?php declare(strict_types=1);

namespace GES\Botlock\Http;

readonly class Cookie
{
    /**
     * @param string      $name      Name of the Cookie
     * @param string      $value     Value of the Cookie
     * @param int|null    $expires   Unix timestamp for expiration time (null = session cookie)
     * @param string      $path      Path for which the cookie is valid
     * @param string|null $domain    Domain for which the cookie is valid (null = current domain)
     * @param bool        $secure    Only transmit over HTTPS
     * @param bool        $httpOnly  Only accessible via HTTP(S) (not JavaScript)
     * @param string|null $sameSite  'Lax', 'Strict' oder 'None'
     */
    public function __construct(
        private string $name,
        private string $value,
        private ?int $expires = null,
        private string $path = '/',
        private ?string $domain = null,
        private bool $secure = false,
        private bool $httpOnly = true,
        private ?string $sameSite = 'Lax'
    ) {}

    public function send(): bool
    {
        return setcookie($this->name, $this->value, [
            'expires'  => $this->expires ?? 0,
            'path'     => $this->path,
            'domain'   => $this->domain,
            'secure'   => $this->secure,
            'httponly' => $this->httpOnly,
            'samesite' => $this->sameSite,
        ]);
    }

    public static function create(
        string $name,
        string $value,
        int $ttlSeconds = 0,
        string $path = '/',
        ?string $domain = null,
        bool $secure = false,
        bool $httpOnly = true,
        ?string $sameSite = 'Lax'
    ): self {
        $expires = $ttlSeconds > 0 ? time() + $ttlSeconds : null;
        return new self($name, $value, $expires, $path, $domain, $secure, $httpOnly, $sameSite);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getExpires(): ?int
    {
        return $this->expires;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    public function getSameSite(): ?string
    {
        return $this->sameSite;
    }
}