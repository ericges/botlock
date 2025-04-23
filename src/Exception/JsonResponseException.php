<?php

namespace GES\Botlock\Exception;

use Exception;

class JsonResponseException extends Exception
{
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getData(): array
    {
        return [
            'ok' => false,
            'error' => $this->getMessage(),
            'code' => $this->getCode(),
        ];
    }
}