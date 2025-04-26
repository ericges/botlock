<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Config;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\RateLimiter;

readonly class RateLimiterMiddleware implements MiddlewareInterface
{
    public function __construct(private Config $config) {}

    public function process(Request $request, callable $next): Response
    {
        if (!\is_null($override = $this->config->getThreatLevelOverride()))
        {
            $request->bind('threatLevel', $override);

            return $next($request);
        }

        if (!$this->config->isRateLimitEnabled())
        {
            $request->bind('threatLevel', 1);

            return $next($request);
        }

        $rateLimiter = new RateLimiter($this->config);

        $rateLimiter->recordRequest($request);

        if ($this->config->isGlobalRateLimitEnabled())
        {
            $globalThreatLevel = $rateLimiter->getGlobalThreatLevel();
            $request->bind('threatLevelGlobal', $globalThreatLevel);
        }

        if ($this->config->isIndividualRateLimitEnabled())
        {
            if (!$fingerprint = $request->fingerprint) {
                throw new \RuntimeException('Fingerprint not set');
            }

            $individualRate = $rateLimiter->getIndividualRate($fingerprint);
            $request->bind('individualRate', $individualRate);

            $individualThreatLevel = $rateLimiter->getIndividualThreatLevel($fingerprint);
            $request->bind('threatLevelIndividual', $individualThreatLevel);
        }

        $threatLevel = \max($globalThreatLevel ?? 0, $individualThreatLevel ?? 0);
        $request->bind('threatLevel', $threatLevel);

        return $next($request);
    }
}