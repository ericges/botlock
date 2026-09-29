<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Manager\ThreatAwarenessManager;

final readonly class ThreatEvaluationMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $uncountedGlobally actions (see Request::getBotlockAction()) counted
     *                                        toward the client's rate only, see ChallengeStepInterface
     */
    public function __construct(
        private RateLimitConfig $config,
        private ThreatAwarenessManager $rateLimiter,
        private array $uncountedGlobally = [],
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

        $this->rateLimiter->recordRequest(
            $request,
            countGlobally: !\in_array($request->getBotlockAction(), $this->uncountedGlobally, true),
        );

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
