<?php declare(strict_types=1);

namespace GES\Botlock\Http;

class Response
{
    /**
     * @param int                  $status  HTTP status code
     * @param array<string,string> $headers HTTP header as key-value pairs
     * @param string|null          $body    body content
     */
    public function __construct(
        private int              $status = 200,
        private array            $headers = [],
        private readonly ?string $body = null,
        private array            $cookies = [],
    ) {}

    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withStatus(int $status): static
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function send(): void
    {
        $this->sendResponseCode();
        $this->sendHeaders();
        $this->sendCookies();
        $this->sendBody();
    }

    protected function sendResponseCode(): void
    {
        \http_response_code($this->status);
    }

    protected function sendHeaders(): void
    {
        foreach ($this->getHeaders() as $name => $value) {
            \header(\sprintf('%s: %s', $name, $value));
        }
    }

    protected function sendCookies(): void
    {
        foreach ($this->cookies as $cookie) {
            if ($cookie instanceof Cookie) {
                $cookie->send();
            }
        }
    }

    protected function sendBody(): void
    {
        if (null !== ($body = $this->getBody())) {
            echo $body;
        }
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->headers[$name] ?? $default;
    }

    protected function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function setCookie(Cookie $cookie): void
    {
        $this->cookies[$cookie->getName()] = $cookie;
    }
}