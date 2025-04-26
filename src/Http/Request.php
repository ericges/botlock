<?php declare(strict_types=1);

namespace GES\Botlock\Http;

use GES\Botlock\Http\Session;

/**
 * @property int     $threatLevel
 * @property int     $threatLevelGlobal
 * @property int     $threatLevelIndividual
 * @property int     $individualRate
 * @property string  $fingerprint
 * @property Session $session
 */
class Request
{
    private readonly array $urlParts;
    private array $bindings = [];

    public function __construct(
        private readonly string $method,
        private readonly string $requestUrl,
        private readonly bool   $secure = false,
        private readonly array  $headers = [],
        private readonly array  $queryParams = [],
        private readonly array  $cookies = [],
        private readonly string $body = '',
    ) {
        if (!($urlParts = \parse_url($requestUrl)) || !isset($urlParts['scheme'], $urlParts['host'])) {
            throw new \InvalidArgumentException('Invalid request URL');
        }

        $this->urlParts = $urlParts;
    }

    public static function fromGlobals(): static
    {
        $method = \strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $secure = \strtolower($_SERVER['HTTPS'] ?? '') === 'on' || $_SERVER['SERVER_PORT'] === '443';

        $scheme = $secure ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
        $port = (string) $_SERVER['SERVER_PORT'] ?? null;
        $uri = '/' . \ltrim($_SERVER['REQUEST_URI'] ?? '', '/');

        if ($port && !\in_array($port, ['80', '443'])) {
            $host .= ':' . $port;
        }

        $requestUrl = "$scheme://$host$uri";

        if (\function_exists('getallheaders'))
        {
            $headers = getallheaders();
        }
        else
        {
            $headers = [];

            foreach ($_SERVER as $key => $value)
            {
                if (\str_starts_with($key, 'HTTP_'))
                {
                    $name = \str_replace(' ', '-', \ucwords(\strtolower(
                        \str_replace('_', ' ', \substr($key, 5)),
                    )));
                    $headers[$name] = $value;
                }
            }
        }

        $body = \file_get_contents('php://input') ?: '';

        return new static(
            method: $method,
            requestUrl: $requestUrl,
            secure: $secure,
            headers: $headers,
            queryParams: $_GET,
            cookies: $_COOKIE,
            body: $body,
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return \strtoupper(\trim($this->method)) === \strtoupper(\trim($method));
    }

    public function getRequestUrl(): string
    {
        return $this->requestUrl;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->headers[$name] ?? $default;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getCookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function hasCookie(string $name): bool
    {
        return isset($this->cookies[$name]);
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function get($key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function getAbsoluteUrl(string $path): ?string
    {
        $url = $this->urlParts['scheme'] . '://' . $this->urlParts['host'];

        if (isset($urlParts['port'])) {
            $url .= ':' . $urlParts['port'];
        }

        $url .= '/' . \ltrim($path, '/');
        $url = \rtrim($url, '/');

        if (!\filter_var($url, \FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    public function getHost(): ?string
    {
        return $this->urlParts['host'] ?? null;
    }

    public function getUrlWithoutParameters(): ?string
    {
        return $this->getAbsoluteUrl($this->urlParts['path'] ?? '');
    }

    public function bind(string $name, mixed $value): static
    {
        if (isset($this->bindings[$name])) {
            throw new \InvalidArgumentException("Binding '$name' already exists");
        }

        $this->bindings[$name] = $value;

        return $this;
    }

    public function __get(string $name): mixed
    {
        if (isset($this->bindings[$name])) {
            return $this->bindings[$name];
        }

        return null;
    }
}