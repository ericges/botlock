<?php

namespace GES\Botlock;

class Config
{
    public final const ALLOWED_ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    private readonly int $expire;
    private readonly int $maxNumber;
    private readonly string $algorithm;
    private readonly string $secret;
    private readonly string $issuer;
    private readonly string $agent;

    public function __construct(
        ?int $expire = null,
        ?int $maxNumber = null,
        ?string $algorithm = null,
        ?string $secret = null,
        ?string $audience = null,
    ) {
        $this->algorithm = \strtolower($algorithm ?? env('ALGORITHM')) ?: 'sha256';
        if (!in_array($this->algorithm, self::ALLOWED_ALGORITHMS)) {
            throw new \Exception('Invalid algorithm provided');
        }

        $this->secret = \trim($secret ?: $this->loadSecret());
        $this->issuer = \trim($audience ?? $this->loadIssuer());
        $this->expire = $expire ?? (int) (env('EXPIRE') ?: 60);
        $this->maxNumber = $maxNumber ?? (int) (env('MAX_NUMBER') ?: 100000);
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    public function getIssuer(): string
    {
        return $this->issuer;
    }

    public function getExpire(): int
    {
        return $this->expire;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getMaxNumber(): int
    {
        return $this->maxNumber;
    }

    private function loadIssuer(): string
    {
        if ($issuer = env('ISSUER')) {
            return $issuer;
        }

        $requestUrl = getRequestUrl();

        $urlParts = parse_url($requestUrl);
        $url = $urlParts['scheme'] . '://' . $urlParts['host'];

        if (isset($urlParts['port'])) {
            $url .= ':' . $urlParts['port'];
        }

        if (isset($urlParts['path'])) {
            $url .= $urlParts['path'];
        }

        return $url;
    }

    private function loadSecret(): string
    {
        if ($secret = env('SECRET')) {
            return $secret;
        }

        $tmpdir = sys_get_temp_dir();

        if (!@file_exists($tmpdir) && !@is_dir($tmpdir) && !@mkdir($tmpdir)) {
            throw new \Exception('TMPDIR does not exist and could not be created');
        }

        $secretFilename = 'ges_botlock_secret';
        $secretFile = $tmpdir . DIRECTORY_SEPARATOR . $secretFilename;

        if (@file_exists($secretFile)) {
            if (!$secret = file_get_contents($secretFile))
            {
                throw new \Exception('Could not read contents of "' . $secretFile . '"');
            }

            return $secret;
        }

        if (!@is_writable($tmpdir) && !@chmod($tmpdir, '0755')) {
            throw new \Exception('TMPDIR is not writable and could not be chmoded');
        }

        $secret = randStr(32);

        if (!file_put_contents($secretFile, $secret)) {
            throw new \Exception('Could not write secret to file "' . $secretFile . '"');
        }

        return $secret;
    }
}
