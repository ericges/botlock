<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\BotDetect;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Whitelist;

readonly class WhitelistMiddleware implements MiddlewareInterface
{
    public function __construct(
        private BotDetect $botDetect,
        private Whitelist $whitelist,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->threatLevel === 0) {
            return new Response\PassResponse;
        }

        if (\is_int($request->threatLevelIndividual) && $request->threatLevelIndividual < 2) {
            if ($this->botDetect->isGoodBot()) {
                return new Response\PassResponse;
            }
        }

        if ($this->whitelist->isRequestWhitelisted($request)) {
            return new Response\PassResponse;
        }

        return $next($request);
    }
}