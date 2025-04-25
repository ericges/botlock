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
        $this->rateLimiter->recordRequest($request);

        if (!$fingerprint = $request->fingerprint) {
            throw new \RuntimeException('Fingerprint not set');
        }

        // Calculate levels (might read from cache within RateLimiter)
        $globalThreatLevel = $this->rateLimiter->getGlobalThreatLevel();
        $individualRate = $this->rateLimiter->getIndividualRate($fingerprint);
        $individualThreatLevel = $this->rateLimiter->getIndividualThreatLevel($fingerprint);
        $threatLevel = \max($globalThreatLevel, $individualThreatLevel);

        // Bind values to the request for later middleware
        $request->bind('threatLevelGlobal', $globalThreatLevel);
        $request->bind('individualRate', $individualRate);
        $request->bind('threatLevelIndividual', $individualThreatLevel);
        $request->bind('threatLevel', $threatLevel);

        // Proceed to the next middleware
        return $next($request);
    }
}