<?php

namespace GES\Botlock\Http\Middleware;

use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

interface MiddlewareInterface
{
    public function process(Request $request, callable $next): Response;
}