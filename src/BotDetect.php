<?php

namespace GES\Botlock;

use Jaybizzle\CrawlerDetect\CrawlerDetect;

class BotDetect
{
    private bool $isCrawler;
    private ?string $crawlerMatch = null;

    public function __construct(private readonly Config $config) {}

    public function isBot(): bool
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
        if (!$this->isBot())
        {
            return false;
        }

        $match = \strtolower($this->crawlerMatch ?? '');
        $goodBots = \array_map(
            static fn($bot): string => \trim(\strtolower((string) $bot)),
            $this->config->getGoodBots(),
        );

        return $match && \array_filter($goodBots, static fn($bot): bool => \str_contains($match, $bot));
    }
}