<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Manager\WhitelistManager;

readonly class WhitelistMiddleware implements MiddlewareInterface
{
    public function __construct(
        private BotTestManager   $detective,
        private WhitelistManager $whitelist,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->getBotlockAction()) {
            return $next($request);
        }

        if ($request->context->threatLevel === 0) {
            return new Response\PassResponse;
        }

        if (\is_int($request->context->threatLevelIndividual) && $request->context->threatLevelIndividual < 2 && $this->detective->isGoodBot()) {
            return new Response\PassResponse;
        }

        if ($this->whitelist->isRequestWhitelisted($request)) {
            return new Response\PassResponse;
        }

        return $next($request);
    }
}