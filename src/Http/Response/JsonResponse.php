<?php

namespace GES\Botlock\Http\Response;

use GES\Botlock\Http\Response;

class JsonResponse extends Response
{
    public function __construct(int $status, mixed $data, array $headers = [])
    {
        $body = \json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $baseHeaders = ['Content-Type' => 'application/json; charset=utf-8'];
        parent::__construct($status, \array_merge($baseHeaders, $headers), $body);
    }
}