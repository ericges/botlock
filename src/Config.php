<?php

namespace GES\Botlock;

class Config
{
    public final const ALLOWED_ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    private readonly int $expire;
    private readonly int $maxNumber;
    private readonly int $crawlerFactor;
    private readonly bool $dnsChecks;
    private readonly string $algorithm;
    private readonly string $secret;
    private readonly string $issuer;
    private readonly string $instanceId;
    private readonly ?array $ignoreIps;
    private readonly array $ignoreUserAgents;
    private readonly ?array $ignoreUrls;
    private readonly array $goodBots;
    private readonly array $trustedProxies;

    public function __construct(
        ?int    $expire = null,
        ?string $algorithm = null,
        ?int    $maxNumber = null,
        ?int    $crawlerFactor = null,
        ?bool   $dnsChecks = null,
        ?string $secret = null,
        ?string $issuer = null,
        ?string $instanceId = null,
        ?array  $ignoreIps = null,
        ?array  $ignoreUserAgents = null,
        ?array  $ignoreUrls = null,
        ?array  $goodBots = null,
        ?array  $trustedProxies = null,
    ) {
        $this->algorithm = \strtolower($algorithm ?? env('ALGORITHM', '')) ?: 'sha256';
        if (!in_array($this->algorithm, self::ALLOWED_ALGORITHMS)) {
            throw new \Exception('Invalid algorithm provided');
        }

        $this->instanceId = $instanceId ?: env('INSTANCE_ID') ?: \md5(__DIR__);
        $this->secret = \trim($secret ?: $this->loadSecret());
        $this->issuer = \trim($issuer ?? $this->loadIssuer());
        $this->expire = $expire ?? (int) (env('EXPIRE') ?: 3600);
        $this->maxNumber = $maxNumber ?? (int) (env('MAX_NUMBER') ?: 100000);
        $this->crawlerFactor = (int) ($crawlerFactor ?? (env('CRAWLER_FACTOR') ?: 100));
        $this->ignoreIps = $ignoreIps ?: $this->loadIgnoreIps();
        $this->ignoreUserAgents = $ignoreUserAgents ?? $this->loadIgnoreUserAgents();
        $this->ignoreUrls = $ignoreUrls ?: $this->loadIgnoreUrls();
        $this->goodBots = $goodBots ?? $this->loadGoodBots();
        $this->trustedProxies = $trustedProxies ?? envArray('TRUSTED_PROXIES');
        $this->dnsChecks = (bool) ($dnsChecks ?? env('DNS_CHECKS', true));
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

    public function getCrawlerFactor(): int
    {
        return \max(1, $this->crawlerFactor);
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getIgnoreIps(): ?array
    {
        return $this->ignoreIps;
    }

    public function getIgnoreUserAgents(): ?array
    {
        return $this->ignoreUserAgents;
    }

    public function getIgnoreUrls(): ?array
    {
        return $this->ignoreUrls;
    }

    public function getGoodBots(): array
    {
        return $this->goodBots;
    }

    public function getTrustedProxies(): ?array
    {
        return $this->trustedProxies;
    }

    public function getDnsChecks(): bool
    {
        return $this->dnsChecks;
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

        return \trim(\rtrim($url, '/'));
    }

    private function loadSecret(): string
    {
        if ($secret = env('SECRET')) {
            return $secret;
        }

        $tmpdir = \sys_get_temp_dir();

        if (!@\file_exists($tmpdir) && !@\is_dir($tmpdir) && !@\mkdir($tmpdir)) {
            throw new \Exception('TMPDIR does not exist and could not be created');
        }

        $secretFilename = 'botlock_secret_' . $this->getInstanceId();
        $secretFile = $tmpdir . DIRECTORY_SEPARATOR . $secretFilename;

        if (@\file_exists($secretFile)) {
            if (!$secret = \file_get_contents($secretFile))
            {
                throw new \Exception('Could not read contents of "' . $secretFile . '"');
            }

            return $secret;
        }

        if (!@\is_writable($tmpdir) && !@\chmod($tmpdir, '0755')) {
            throw new \Exception('TMPDIR is not writable and could not be chmoded');
        }

        $secret = randStr(32);

        if (!\file_put_contents($secretFile, $secret)) {
            throw new \Exception('Could not write secret to file "' . $secretFile . '"');
        }

        return $secret;
    }

    private function loadIgnoreIps(): ?array
    {
        if ($ignoreIps = envArray('IGNORE_IPS')) {
            $ignoreIps = \array_unique(\array_map('trim', $ignoreIps));
            return empty($ignoreIps) ? null : $ignoreIps;
        }

        return null;
    }

    private function loadIgnoreUserAgents(): array
    {
        $defaultIgnoreUserAgents = [
            'Googlebot',
        ];

        if ($ignoreUserAgents = envArray('IGNORE_USER_AGENTS')) {
            $ignoreUserAgents = \array_unique(\array_map('trim', $ignoreUserAgents));
            return empty($ignoreUserAgents) ? $defaultIgnoreUserAgents : $ignoreUserAgents;
        }

        return $defaultIgnoreUserAgents;
    }

    private function loadIgnoreUrls(): ?array
    {
        if ($ignoreUrls = envArray('IGNORE_URLS')) {
            $ignoreUrls = \array_unique(\array_map('trim', $ignoreUrls));
            return empty($ignoreUrls) ? null : $ignoreUrls;
        }

        return null;
    }

    private function loadGoodBots(): array
    {
        $defaultGoodBots = [
            'Googlebot',
            'Bingbot',
            'DuckDuckBot',
            'Exabot',
            'facebot',
        ];

        if ($goodBots = envArray('GOOD_BOTS')) {
            $goodBots = \array_unique(\array_map('trim', $goodBots));
            return empty($goodBots) ? $defaultGoodBots : $goodBots;
        }

        return $defaultGoodBots;
    }
}
