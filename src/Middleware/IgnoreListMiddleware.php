<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Manager\WhitelistManager;

/**
 * Lets requests matching the configured IP, User-Agent or URL exclusions
 * through before any rate-limit state is recorded, so excluded traffic
 * never contributes to threat levels.
 *
 * Botlock actions (?_botlock=...) are never short-circuited here so that
 * excluded clients can still query the status endpoint.
 */
final readonly class IgnoreListMiddleware implements MiddlewareInterface
{
    public function __construct(private WhitelistManager $whitelist) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->getBotlockAction() === null && $this->whitelist->isRequestWhitelisted($request)) {
            return new PassResponse();
        }

        return $next($request);
    }
}
