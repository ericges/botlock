<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Config;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\RateLimiter;

readonly class RateLimiterMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Config      $config,
        private RateLimiter $rateLimiter,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->config->getThreatLevelOverride() !== null)
        {
            $request->bind('threatLevel', $this->config->getThreatLevelOverride());

            return $next($request);
        }

        if (!$this->config->isRateLimitEnabled())
        {
            $request->bind('threatLevel', 1);

            return $next($request);
        }

        $this->rateLimiter->recordRequest($request);

        if (!$fingerprint = $request->fingerprint) {
            throw new \RuntimeException('Fingerprint not set');
        }

        // Calculate levels (might read from cache within RateLimiter)
        if ($this->config->isGlobalRateLimitEnabled())
        {
            $globalThreatLevel = $this->rateLimiter->getGlobalThreatLevel();
            $request->bind('threatLevelGlobal', $globalThreatLevel);
        }

        if ($this->config->isIndividualRateLimitEnabled())
        {
            $individualRate = $this->rateLimiter->getIndividualRate($fingerprint);
            $request->bind('individualRate', $individualRate);

            $individualThreatLevel = $this->rateLimiter->getIndividualThreatLevel($fingerprint);
            $request->bind('threatLevelIndividual', $individualThreatLevel);
        }

        $threatLevel = \max($globalThreatLevel ?? 0, $individualThreatLevel ?? 0);
        $request->bind('threatLevel', $threatLevel);

        // Proceed to the next middleware
        return $next($request);
    }
}