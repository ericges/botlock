<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\RateLimiter;

readonly class RateLimiterMiddleware implements MiddlewareInterface
{
    public function __construct(private RateLimiter $rateLimiter) {}

    public function process(Request $request, callable $next): Response
    {
        // Record the request AFTER session is established
        $this->rateLimiter->recordRequest($request);

        // Calculate levels (might read from cache within RateLimiter)
        $globalThreatLevel = $this->rateLimiter->getGlobalThreatLevel();
        $individualRate = 0;
        if (isset($request->session)) { // Ensure session middleware ran
            $fingerprint = $request->session->getSub();
            $individualRate = $this->rateLimiter->getIndividualRate($fingerprint);
        } else {
            // Log Error: Session not found when expected
        }

        // Bind values to the request for later middleware
        $request->bind('threatLevel', $globalThreatLevel);
        $request->bind('individualRate', $individualRate);

        // Proceed to the next middleware
        return $next($request);
    }
}