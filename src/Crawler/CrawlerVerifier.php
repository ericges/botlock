<?php declare(strict_types=1);

namespace GES\Botlock\Crawler;

/**
 * Confirms that a client IP belongs to a crawler provider.
 */
interface CrawlerVerifier
{
    /**
     * @param string $provider key of DetectionConfig::VERIFIABLE_BOTS, e.g. "google"
     * @param string $ip       resolved client IP
     * @return CrawlerVerification Verified or Failed; Unverified for an unknown provider
     */
    public function verify(string $provider, string $ip): CrawlerVerification;
}
