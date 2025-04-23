<?php

namespace GES\Botlock\Http\Response;

use GES\Botlock\Http\Response;

class RedirectResponse extends Response
{
    public function __construct(string $location, int $status = 303)
    {
        parent::__construct($status, ['Location' => $location]);
    }
}