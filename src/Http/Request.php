<?php

declare(strict_types=1);

namespace GES\Botlock\Http;

class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array  $headers = [],
        private readonly array  $queryParams = [],
        private readonly string $body = '',
    ) {}

    public static function fromGlobals(): static
    {
        $method = \strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';

        if (\function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (\str_starts_with($key, 'HTTP_')) {
                    $name = \str_replace(' ', '-', \ucwords(\strtolower(\str_replace('_', ' ', \substr($key, 5)))));
                    $headers[$name] = $value;
                }
            }
        }

        $queryParams = $_GET;
        $body = \file_get_contents('php://input') ?: '';

        return new static($method, $uri, $headers, $queryParams, $body);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getHeaders(): array
    {
        return $this->headers;
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
}