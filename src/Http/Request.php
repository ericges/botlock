<?php declare(strict_types=1);

namespace GES\Botlock\Http;

/**
 * @property int     $threatLevel
 * @property int     $threatLevelGlobal
 * @property int     $threatLevelIndividual
 * @property int     $individualRate
 * @property string  $clientIp
 * @property string  $fingerprint
 * @property Session $session
 */
class Request
{
    private readonly string $method;
    private readonly array $urlParts;
    private readonly array $normalizedHeaders;
    private array $bindings = [];

    public function __construct(
        string                  $method,
        private readonly string $requestUrl,
        private readonly bool   $secure = false,
        private readonly array  $server = [],
        private readonly array  $rawHeaders = [],
        private readonly array  $queryParams = [],
        private readonly array  $cookies = [],
        private readonly string $body = '',
        private readonly array  $postParams = [],
    ) {
        if (!($urlParts = \parse_url($requestUrl)) || !isset($urlParts['scheme'], $urlParts['host'])) {
            throw new \InvalidArgumentException('Invalid request URL');
        }

        $this->method = \strtoupper(\trim($method));
        $this->urlParts = $urlParts;

        $normalizedHeaders = [];
        foreach ($this->rawHeaders as $name => $value) {
            $normalizedHeaders[\strtolower((string) $name)] = $value;
        }
        $this->normalizedHeaders = $normalizedHeaders;
    }

    public static function fromGlobals(): static
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $secure = \strtolower($_SERVER['HTTPS'] ?? '') === 'on' || $_SERVER['SERVER_PORT'] === '443';

        $scheme = $secure ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
        $port = (string) ($_SERVER['SERVER_PORT'] ?? '');
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
        $server = [
            'REQUEST_TIME' => $_SERVER['REQUEST_TIME'] ?? null,
            'REQUEST_TIME_FLOAT' => $_SERVER['REQUEST_TIME_FLOAT'] ?? null,
            'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
            'REMOTE_PORT' => $_SERVER['REMOTE_PORT'] ?? null,
            'REMOTE_HOST' => $_SERVER['REMOTE_HOST'] ?? null,
            'REMOTE_USER' => $_SERVER['REMOTE_USER'] ?? null,
        ];

        return new static(
            method: $method,
            requestUrl: $requestUrl,
            secure: $secure,
            server: $server,
            rawHeaders: $headers,
            queryParams: $_GET,
            cookies: $_COOKIE,
            body: $body,
            postParams: $_POST,
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

    public function getServer(): array
    {
        return $this->server;
    }

    public function getServerParam(string $name, ?string $default = null): ?string
    {
        return $this->server[$name] ?? $default;
    }

    public function getRawHeaders(): array
    {
        return $this->rawHeaders;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->normalizedHeaders[\strtolower($name)] ?? $default;
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

    /**
     * Decodes the raw body as a JSON object. Returns null when the body is
     * empty, not valid JSON, or not a JSON object/array.
     */
    public function getJsonBody(): ?array
    {
        if ($this->body === '') {
            return null;
        }

        $data = \json_decode($this->body, true);

        return \is_array($data) ? $data : null;
    }

    public function getPostParams(): array
    {
        return $this->postParams;
    }

    public function getPost(string $key, mixed $default = null): mixed
    {
        return $this->postParams[$key] ?? $default;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function get($key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Returns the action for botlock, if present.
     * Example: GET challenge, POST verify, POST reset, GET status
     */
    public function getBotlockAction(): ?string
    {
        if ($action = $this->get('_botlock')) {
            $action = \preg_replace('/[^a-z0-9_]/', '', \strtolower($action));
            return $this->getMethod() . ' ' . $action;
        }

        return null;
    }

    public function getAbsoluteUrl(string $path): ?string
    {
        $url = $this->getOrigin() . '/' . \ltrim($path, '/');
        $url = \rtrim($url, '/');

        if (!\filter_var($url, \FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    public function getOrigin(): string
    {
        $origin = $this->urlParts['scheme'] . '://' . $this->urlParts['host'];

        if (isset($this->urlParts['port'])) {
            $origin .= ':' . $this->urlParts['port'];
        }

        return $origin;
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
        $this->bindings[$name] = $value;

        return $this;
    }

    public function __get(string $name): mixed
    {
        return $this->bindings[$name] ?? null;
    }
}