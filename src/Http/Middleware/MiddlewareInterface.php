<?php

namespace GES\Botlock\Http\Middleware;

use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

interface MiddlewareInterface
{
    /**
     * @param Request                     $request The request to process
     * @param callable(Request): Response $next    The next middleware in the stack
     * @return Response
     */
    public function process(Request $request, callable $next): Response;
}