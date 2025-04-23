<?php declare(strict_types=1);

namespace GES\Botlock\Http;

class Response
{
    /**
     * @param int                  $status  HTTP status code
     * @param array<string,string> $headers HTTP header as key-value pairs
     * @param string|null          $body    body content
     */
    public function __construct(
        private int              $status = 200,
        private array            $headers = [],
        private readonly ?string $body = null,
    ) {}

    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withStatus(int $status): static
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->getHeaders() as $name => $value) {
            header(sprintf('%s: %s', $name, $value));
        }

        if (null !== ($body = $this->getBody())) {
            echo $body;
        }
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}