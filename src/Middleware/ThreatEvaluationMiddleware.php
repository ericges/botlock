<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Manager\ThreatAwarenessManager;

readonly class ThreatEvaluationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimitConfig $config,
        private ThreatAwarenessManager $rateLimiter,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if (!\is_null($override = $this->config->threatLevelOverride))
        {
            $request->context->threatLevel = $override;

            return $next($request);
        }

        if (!$this->config->isRateLimitEnabled())
        {
            $request->context->threatLevel = 1;

            return $next($request);
        }

        $this->rateLimiter->recordRequest($request);

        if ($this->config->enableGlobalRateLimit)
        {
            $globalThreatLevel = $this->rateLimiter->getGlobalThreatLevel();
            $request->context->threatLevelGlobal = $globalThreatLevel;
        }

        if ($this->config->enableIndividualRateLimit)
        {
            if (!$fingerprint = $request->context->fingerprint) {
                throw new \RuntimeException('Fingerprint not set');
            }

            $individualRate = $this->rateLimiter->getIndividualRate($fingerprint);
            $request->context->individualRate = $individualRate;

            $individualThreatLevel = $this->rateLimiter->getIndividualThreatLevel($fingerprint);
            $request->context->threatLevelIndividual = $individualThreatLevel;
        }

        $threatLevel = \max($globalThreatLevel ?? 0, $individualThreatLevel ?? 0);
        $request->context->threatLevel = $threatLevel;

        return $next($request);
    }
}