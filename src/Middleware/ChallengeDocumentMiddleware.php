<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Template\LocalizedPage;

/**
 * Serves the browser challenge page in the language negotiated from
 * Accept-Language unless the session holds a grant for at least the
 * current threat level.
 */
final readonly class ChallengeDocumentMiddleware implements MiddlewareInterface
{
    public function __construct(private LocalizedPage $page) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->context->isGrantSufficient())
        {
            return $next($request);
        }

        $request->context->session->commit();

        return $this->page->respond($request, 401);
    }
}
