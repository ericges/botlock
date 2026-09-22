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
            'subject' => $request->context->fingerprint,
            'threat_level' => $request->context->threatLevel,
            'threat_level_global' => $request->context->threatLevelGlobal,
            'threat_level_individual' => $request->context->threatLevelIndividual,
            'individual_rate' => $request->context->individualRate,
            'passed' => $request->context->session?->get('grant'),
        ];

        return new Response\JsonResponse(200, $status);
    }
}