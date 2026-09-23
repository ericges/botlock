<?php declare(strict_types=1);

namespace GES\Botlock\Crawler;

use GES\Botlock\VerifyBot;

/**
 * Verifies crawlers through reverse and forward DNS lookups.
 */
final readonly class DnsCrawlerVerifier implements CrawlerVerifier
{
    public function verify(string $provider, string $ip): CrawlerVerification
    {
        return match ($provider) {
            'google' => VerifyBot::google($ip) ? CrawlerVerification::Verified : CrawlerVerification::Failed,
            default => CrawlerVerification::Unverified,
        };
    }
}
