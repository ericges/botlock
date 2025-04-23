<?php declare(strict_types=1);

namespace GES\Botlock\Http;

readonly class Request
{
    public function __construct(
        private string $method,
        private string $requestUrl,
        private bool   $secure = false,
        private array  $headers = [],
        private array  $queryParams = [],
        private string $body = '',
    ) {}

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
                        \str_replace('_', ' ', \substr($key, 5))
                    )));
                    $headers[$name] = $value;
                }
            }
        }

        $queryParams = $_GET;
        $body = \file_get_contents('php://input') ?: '';

        return new static(
            method: $method,
            requestUrl: $requestUrl,
            secure: $secure,
            headers: $headers,
            queryParams: $queryParams,
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
        $urlParts = \parse_url($this->requestUrl);
        $url = $urlParts['scheme'] . '://' . $urlParts['host'];

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
}