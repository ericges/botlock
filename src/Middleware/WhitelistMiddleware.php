<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Whitelist;

readonly class WhitelistMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Whitelist $whitelist,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->whitelist->isRequestWhitelisted($request)) {
            return new Response\PassResponse;
        }

        return $next($request);
    }
}