<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

/**
 * Answers threat level 4 with 429 Too Many Requests instead of a challenge.
 * Nobody who is not whitelisted is exempt, trusted good bots included: a
 * client this fast is refused until its individual rate drops again.
 *
 * GET ?_botlock=status stays reachable so a blocked client can still see
 * why.
 */
final readonly class ThreatBlockMiddleware implements MiddlewareInterface
{
    public function __construct(private RateLimitConfig $config) {}

    public function process(Request $request, callable $next): Response
    {
        if (($request->context->threatLevel ?? 0) < RateLimitConfig::MAX_LEVEL
            || $request->getBotlockAction() === 'GET status')
        {
            return $next($request);
        }

        return ErrorMiddleware::createErrorResponse($request, 429, 'Too Many Requests')
            ->withHeader('Retry-After', (string) \max(1, $this->config->individualRateWindowSec));
    }
}
