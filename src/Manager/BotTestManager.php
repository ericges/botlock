<?php

namespace GES\Botlock\Manager;

use GES\Botlock\Config\DetectionConfig;
use Jaybizzle\CrawlerDetect\CrawlerDetect;

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

    public function isGoodBot(): bool
    {
        if (!$this->isCrawler())
        {
            return false;
        }

        $match = \strtolower($this->crawlerMatch ?? '');
        $goodBots = \array_map(
            static fn($bot): string => \trim(\strtolower((string) $bot)),
            $this->config->goodBots,
        );

        return $match && \array_filter($goodBots, static fn($bot): bool => \str_contains($match, $bot));
    }
}