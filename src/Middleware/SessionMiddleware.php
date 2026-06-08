<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Manager\ConfigManager;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\JWT;
use GES\Botlock\Http\Session;

readonly class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private ConfigManager $config) {}

    /** {@inheritDoc} */
    public function process(Request $request, callable $next): Response
    {
        $session = new Session($this->config, $request);
        $request->bind('session', $session);

        $response = $next($request);

        if ($session->isCommited()) {
            $response->setCookie($session->createLegacyCookieRemoval());
            $response->setCookie($session->createCookie());
        }

        return $response;
    }
}