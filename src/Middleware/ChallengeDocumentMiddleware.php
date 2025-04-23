<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Http\Response\HtmlFileResponse;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Session;

readonly class ChallengeDocumentMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Session $session,
        private string  $projectRoot,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->session->get('grant', false))
        {
            return $next($request);
        }

        $this->session->write();

        return new HtmlFileResponse($this->projectRoot . '/assets/challenge.html', 401);
    }
}