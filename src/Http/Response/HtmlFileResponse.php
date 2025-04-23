<?php declare(strict_types=1);

namespace GES\Botlock\Http\Response;

use GES\Botlock\Http\Response;

class HtmlFileResponse extends HtmlResponse
{
    private string $filePath;

    /**
     * @param string               $filePath HTML file path
     * @param int                  $status   HTTP status code
     * @param array<string,string> $headers  Additional HTTP headers
     */
    public function __construct(string $filePath, int $status = 200, array $headers = [])
    {
        if (!\is_file($filePath) || !\is_readable($filePath)) {
            throw new \RuntimeException("HTML-Datei nicht gefunden oder nicht lesbar: {$filePath}");
        }

        parent::__construct($status, '', $headers);

        $this->filePath = $filePath;
    }

    public function send(): void
    {
        \http_response_code($this->getStatus());

        $headers = $this->getHeaders();

        if (!isset($headers['Content-Length'])
            && false !== ($filesize = @\filesize($this->filePath))) {
            \header('Content-Length: ' . $filesize);
        }

        foreach ($headers as $name => $value) {
            \header(sprintf('%s: %s', $name, $value));
        }

        \readfile($this->filePath);
    }

    public function getBody(): string
    {
        return \file_get_contents($this->filePath) ?: '';
    }
}