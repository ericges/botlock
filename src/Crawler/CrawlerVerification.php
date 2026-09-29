<?php declare(strict_types=1);

namespace GES\Botlock\Crawler;

/**
 * Outcome of checking whether a crawler is who its User-Agent claims.
 * Set on the request context by VerifyCrawlerMiddleware for every crawler.
 */
enum CrawlerVerification: string
{
    /** No verifier applies (provider not verifiable, not selected, or DNS checks off): trusted by User-Agent alone. */
    case NotApplicable = 'not_applicable';

    /** A verifier is configured for this provider but did not run (no client IP, or the client is at level 4). Never trusted. */
    case Unverified = 'unverified';

    /** The client IP resolves to the provider. */
    case Verified = 'verified';

    /** The client IP does not belong to the provider: a spoofed User-Agent. */
    case Failed = 'failed';

    /**
     * Whether a good-bot User-Agent in this state may be exempted from the challenge.
     */
    public function isTrusted(): bool
    {
        return $this === self::Verified || $this === self::NotApplicable;
    }
}
