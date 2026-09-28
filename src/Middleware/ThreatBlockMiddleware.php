<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Template\LocalizedPage;

/**
 * Answers threat level 4 with 429 Too Many Requests instead of a challenge.
 * Nobody who is not whitelisted is exempt, trusted good bots included: a
 * client this fast is refused until its individual rate drops again.
 * Browsers get the blocked page in their language, JSON clients and
 * everything else the plain error answer; Retry-After is always set.
 * It runs before VerifyCrawlerMiddleware, so a blocked crawler costs no
 * DNS lookups; verification only ever raises a level to 2.
 *
 * GET ?_botlock=status stays reachable so a blocked client can still see
 * why.
 */
final readonly class ThreatBlockMiddleware implements MiddlewareInterface
{
    private const MESSAGE = 'Too Many Requests';

    public function __construct(
        private RateLimitConfig $config,
        private LocalizedPage   $page,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if (($request->context->threatLevel ?? 0) < RateLimitConfig::MAX_LEVEL
            || $request->getBotlockAction() === 'GET status')
        {
            return $next($request);
        }

        $retryAfter = (string) $this->config->retryAfterSec();

        // Same precedence as ErrorMiddleware::createErrorResponse(): JSON wins over HTML.
        $accept = \strtolower($request->getHeader('Accept', ''));

        if (\str_contains($accept, 'text/html') && !\str_contains($accept, 'application/json')) {
            return $this->page->respond($request, 429, [
                'Retry-After' => $retryAfter,
                'Botlock-Error' => self::MESSAGE,
            ]);
        }

        return ErrorMiddleware::createErrorResponse($request, 429, self::MESSAGE)
            ->withHeader('Retry-After', $retryAfter);
    }
}
