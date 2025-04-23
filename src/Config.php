<?php

namespace GES\Botlock;

use Exception;

class Config
{
    public final const POW_ALLOWED_ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    private readonly int $expire;
    private readonly int $maxNumber;
    private readonly int $crawlerFactor;
    private readonly bool $dnsChecks;
    private readonly string $powAlgorithm;
    private readonly string $secret;
    private readonly string $instanceId;
    private readonly ?array $ignoreIps;
    private readonly array $ignoreUserAgents;
    private readonly ?array $ignoreUrls;
    private readonly array $goodBots;
    private readonly array $trustedProxies;
    private readonly string $stateDir;
    private readonly int $globalRateWindowMin;
    private readonly int $individualRateWindowSec;
    private readonly int $level1ThresholdGlobal;
    private readonly int $level2ThresholdGlobal;
    private readonly int $level3ThresholdGlobal;
    private readonly int $level1ThresholdIndividual;
    private readonly int $level2ThresholdIndividual;
    private readonly int $level3ThresholdIndividual;
    private readonly int $levelDecayGracePeriod;

    public function __construct() {
        $this->powAlgorithm = $this->loadPowAlgorithm();
        $this->instanceId = env('INSTANCE_ID') ?: \md5(__DIR__);
        $this->secret = \trim($this->loadSecret());
        $this->expire = (int) (env('EXPIRE') ?: 3600);
        $this->maxNumber = (int) (env('MAX_NUMBER') ?: 50000);
        $this->crawlerFactor = (int) (env('CRAWLER_FACTOR') ?: 15);
        $this->ignoreIps = $this->loadIgnoreIps();
        $this->ignoreUserAgents = $this->loadIgnoreUserAgents();
        $this->ignoreUrls = $this->loadIgnoreUrls();
        $this->goodBots = $this->loadGoodBots();
        $this->trustedProxies = envArray('TRUSTED_PROXIES', []);
        $this->dnsChecks = (bool) (env('DNS_CHECKS', true));

        $this->stateDir = env('STATE_DIR') ?: (\sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'botlock');
        $this->globalRateWindowMin = (int) env('GLOBAL_RATE_WINDOW_MIN', 5);
        $this->individualRateWindowSec = (int) env('INDIVIDUAL_RATE_WINDOW_SEC', 60);
        $this->level1ThresholdGlobal = (int) env('LEVEL_1_THRESHOLD_GLOBAL', 100);
        $this->level2ThresholdGlobal = (int) env('LEVEL_2_THRESHOLD_GLOBAL', 500);
        $this->level3ThresholdGlobal = (int) env('LEVEL_3_THRESHOLD_GLOBAL', 1000);
        $this->level1ThresholdIndividual = (int) env('LEVEL_1_THRESHOLD_INDIVIDUAL', 10);
        $this->level2ThresholdIndividual = (int) env('LEVEL_2_THRESHOLD_INDIVIDUAL', 20);
        $this->level3ThresholdIndividual = (int) env('LEVEL_3_THRESHOLD_INDIVIDUAL', 30);
        $this->levelDecayGracePeriod = (int) env('LEVEL_DECAY_GRACE_PERIOD', 300);  // 5 minutes low traffic to decrease level
    }

    public function getPowAlgorithm(): string { return $this->powAlgorithm; }
    public function getExpire(): int { return $this->expire; }
    public function getSecret(): string { return $this->secret; }
    public function getMaxNumber(): int { return $this->maxNumber; }
    public function getCrawlerFactor(): int { return \max(1, $this->crawlerFactor); }
    public function getInstanceId(): string { return $this->instanceId; }
    public function getIgnoreIps(): ?array { return $this->ignoreIps; }
    public function getIgnoreUserAgents(): ?array { return $this->ignoreUserAgents; }
    public function getIgnoreUrls(): ?array { return $this->ignoreUrls; }
    public function getGoodBots(): array { return $this->goodBots; }
    public function getTrustedProxies(): ?array { return $this->trustedProxies; }
    public function getDnsChecks(): bool { return $this->dnsChecks; }
    public function getStateDir(): string { return $this->stateDir; }
    public function getGlobalRateWindowMin(): int { return $this->globalRateWindowMin; }
    public function getIndividualRateWindowSec(): int { return $this->individualRateWindowSec; }
    public function getLevel1ThresholdGlobal(): int { return $this->level1ThresholdGlobal; }
    public function getLevel2ThresholdGlobal(): int { return $this->level2ThresholdGlobal; }
    public function getLevel3ThresholdGlobal(): int { return $this->level3ThresholdGlobal; }
    public function getLevel1ThresholdIndividual(): int { return $this->level1ThresholdIndividual; }
    public function getLevel2ThresholdIndividual(): int { return $this->level2ThresholdIndividual; }
    public function getLevel3ThresholdIndividual(): int { return $this->level3ThresholdIndividual; }
    public function getLevelDecayGracePeriod(): int { return $this->levelDecayGracePeriod; }

    /**
     * @throws Exception
     */
    private function loadPowAlgorithm(): string
    {
        $algorithm = \strtolower(env('POW_ALGORITHM', 'sha256'));

        if (!\in_array($algorithm, self::POW_ALLOWED_ALGORITHMS)) {
            throw new \Exception('Invalid PoW algorithm provided');
        }

        return $algorithm;
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
            'AdsBot-Google',
        ];

        $ignoreUserAgents = envArray('IGNORE_USER_AGENTS');

        if (\is_array($ignoreUserAgents)) {
            // allows to set an empty array
            return \array_filter(\array_unique(\array_map('trim', $ignoreUserAgents)));
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
            'AdsBot',
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
