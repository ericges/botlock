<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Http\Response\HtmlFileResponse;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

readonly class ChallengeDocumentMiddleware implements MiddlewareInterface
{
    public function __construct(private string $projectRoot) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->session->get('grant', false))
        {
            return $next($request);
        }

        $request->session->commit();

        return new HtmlFileResponse($this->projectRoot . '/assets/challenge.html', 401);
    }
}
