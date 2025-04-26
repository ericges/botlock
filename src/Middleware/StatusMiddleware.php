<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

class StatusMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if ($request->getBotlockAction() === 'GET status') {
            return $this->handleGetStatusRequest($request);
        }

        return $next($request);
    }

    public function handleGetStatusRequest(Request $request): Response
    {
        $status = [
            'user_agent' => $request->getHeader('User-Agent'),
            'subject' => $request->fingerprint,
            'threat_level' => $request->threatLevel ?? null,
            'threat_level_global' => $request->threatLevelGlobal ?? null,
            'threat_level_individual' => $request->threatLevelIndividual ?? null,
            'individual_rate' => $request->individualRate ?? null,
            'passed' => $request->session?->get('grant') ?? null,
        ];

        return new Response\JsonResponse(200, $status);
    }
}