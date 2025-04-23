<?php

namespace GES\Botlock\Http\Middleware;

use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;

class MiddlewareDispatcher
{
    private array $stack = [];

    public function add(MiddlewareInterface $middleware): static
    {
        $this->stack[] = $middleware;
        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $runner = \array_reduce(
            \array_reverse($this->stack),
            static fn(callable $next, MiddlewareInterface $middleware) => fn(Request $request) => $middleware->process($request, $next),
            static fn(Request $request): Response => new PassResponse()
        );

        return $runner($request);
    }
}