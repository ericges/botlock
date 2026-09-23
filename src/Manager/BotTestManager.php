<?php

namespace GES\Botlock\Manager;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Crawler\CrawlerVerification;
use GES\Botlock\Http\RequestContext;
use Jaybizzle\CrawlerDetect\CrawlerDetect;

/**
 * Wraps CrawlerDetect: is the client a crawler, which one, is it one of the
 * configured good bots and which provider can verify it. Matching is
 * case-insensitive throughout.
 */
class BotTestManager
{
    private bool $isCrawler;
    private ?string $crawlerMatch = null;

    public function __construct(private readonly DetectionConfig $config) {}

    public function isCrawler(): bool
    {
        if (!isset($this->isCrawler))
        {
            $crawlerDetect = new CrawlerDetect();
            $this->isCrawler = $crawlerDetect->isCrawler();
            $this->crawlerMatch = $crawlerDetect->getMatches();
        }

        return $this->isCrawler;
    }

    /**
     * The User-Agent fragment CrawlerDetect matched, in the User-Agent's casing.
     */
    public function getCrawlerMatch(): ?string
    {
        return $this->isCrawler() ? $this->crawlerMatch : null;
    }

    /**
     * Whether the crawler is listed in BOTLOCK_GOOD_BOTS. Says nothing about
     * whether the User-Agent is genuine; see isTrustedGoodBot().
     */
    public function isGoodBot(): bool
    {
        return $this->matchesAny($this->config->goodBots);
    }

    /**
     * Provider from BOTLOCK_VERIFY_BOTS whose crawler names match, or null
     * when the client is not a crawler or nobody can verify it.
     */
    public function getVerifyProvider(): ?string
    {
        foreach ($this->config->verifyBots as $provider => $names) {
            if ($this->matchesAny((array) $names)) {
                return (string) $provider;
            }
        }

        return null;
    }

    /**
     * A good bot whose identity is either verified or not verifiable. A good
     * bot whose verification failed or never ran is not trusted.
     */
    public function isTrustedGoodBot(RequestContext $context): bool
    {
        return $this->isGoodBot()
            && ($context->crawlerVerification ?? CrawlerVerification::Unverified)->isTrusted();
    }

    /**
     * @param string[] $names CrawlerDetect names to look for in the match
     */
    private function matchesAny(array $names): bool
    {
        if (!$this->isCrawler() || !($match = \strtolower($this->crawlerMatch ?? ''))) {
            return false;
        }

        foreach ($names as $name) {
            $name = \trim(\strtolower((string) $name));

            if ($name !== '' && \str_contains($match, $name)) {
                return true;
            }
        }

        return false;
    }
}
