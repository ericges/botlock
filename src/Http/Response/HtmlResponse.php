<?php

namespace GES\Botlock\Http\Response;

use GES\Botlock\Http\Response;

class HtmlResponse extends Response
{
    public function __construct(int $status, string $body, array $headers = [])
    {
        $baseHeaders = ['Content-Type' => 'text/html; charset=utf-8'];
        parent::__construct($status, \array_merge($baseHeaders, $headers), $body);
    }
}