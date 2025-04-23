<?php

declare(strict_types=1);

namespace GES\Botlock\Http;

use function GES\Botlock\abort;

class Router
{
    /** @var array<string, array<string, callable(Request): void>> */
    private array $routes = [];
    /** @var callable(Request): void|null */
    private mixed $defaultHandler = null;

    public function addRoute(string $action, string $method, callable $handler): static
    {
        $this->routes[$action][strtoupper($method)] = $handler;
        return $this;
    }

    public function get(string $action, callable $handler): static
    {
        $this->addRoute($action, 'GET', $handler);
        return $this;
    }

    public function post(string $action, callable $handler): static
    {
        $this->addRoute($action, 'POST', $handler);
        return $this;
    }

    public function fallback(callable $handler): static
    {
        $this->defaultHandler = $handler;
        return $this;
    }

    public function dispatch(Request $request): void
    {
        $action = (string) $request->get('_botlock');
        $method = $request->getMethod();

        if ($action)
        {
            if (isset($this->routes[$action][$method]))
                // Method-specific route
            {
                ($this->routes[$action][$method])($request);
                return;
            }

            if (isset($this->routes[$action]))
                // Action exists but wrong method
            {
                abort(405);
            }
        }

        if (\is_callable($this->defaultHandler))
            // Fallback
        {
            ($this->defaultHandler)($request);
            return;
        }

        // No route and no default: 404
        abort(404);
    }

    public static function create(): static
    {
        return new static();
    }
}
