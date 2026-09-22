<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Config\ProofOfWorkConfig;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Session;

final readonly class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private ProofOfWorkConfig $config) {}

    /** {@inheritDoc} */
    public function process(Request $request, callable $next): Response
    {
        if (!$sub = $request->context->fingerprint) {
            throw new \RuntimeException('Fingerprint not set');
        }

        $session = new Session(
            secret: $this->config->secret,
            ttl: $this->config->expire,
            sub: $sub,
            origin: $request->getOrigin(),
            host: (string) $request->getHost(),
            secure: $request->isSecure(),
            cookieJwt: $request->getCookie(Session::COOKIE_NAME),
        );

        $request->context->session = $session;

        $response = $next($request);

        if ($session->isCommited()) {
            $response->setCookie($session->createLegacyCookieRemoval());
            $response->setCookie($session->createCookie());
        }

        return $response;
    }
}
