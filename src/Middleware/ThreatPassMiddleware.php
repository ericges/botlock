<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;

/**
 * Passes requests through to the application when the evaluated threat
 * level does not warrant a challenge: no threshold reached, or a trusted
 * good bot while the effective level is still below 2. Levels 2 and 3
 * challenge everyone who is not explicitly whitelisted.
 */
final readonly class ThreatPassMiddleware implements MiddlewareInterface
{
    public function __construct(private BotTestManager $detective) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->getBotlockAction()) {
            return $next($request);
        }

        $level = $request->context->threatLevel;

        if ($level === 0) {
            return new PassResponse;
        }

        if (\is_int($level) && $level < 2 && $this->detective->isTrustedGoodBot($request->context)) {
            return new PassResponse;
        }

        return $next($request);
    }
}
