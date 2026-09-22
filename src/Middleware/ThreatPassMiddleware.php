<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;

/**
 * Passes requests through to the application when the evaluated threat
 * level does not warrant a challenge: no threshold reached, or a
 * recognized good bot whose individual level is still below 2.
 */
readonly class ThreatPassMiddleware implements MiddlewareInterface
{
    public function __construct(private BotTestManager $detective) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->getBotlockAction()) {
            return $next($request);
        }

        if ($request->context->threatLevel === 0) {
            return new PassResponse;
        }

        $individual = $request->context->threatLevelIndividual;
        if (\is_int($individual) && $individual < 2 && $this->detective->isGoodBot()) {
            return new PassResponse;
        }

        return $next($request);
    }
}
