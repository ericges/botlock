<?php

namespace GES\Botlock\Exception;

use Exception;

class JsonResponseException extends Exception
{
    /**
     * @param array<string, string> $headers sent with the answer besides Botlock-Error
     */
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null, private readonly array $headers = [])
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
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