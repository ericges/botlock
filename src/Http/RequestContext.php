<?php declare(strict_types=1);

namespace GES\Botlock\Http;

use GES\Botlock\Crawler\CrawlerVerification;

/**
 * Per-request state shared between middlewares.
 *
 * Populated progressively while the request travels through the middleware
 * stack: WhoIs sets the client identity, ThreatEvaluation the threat levels,
 * VerifyCrawler the crawler verification, Session the session. Properties
 * are null until the responsible middleware has run.
 */
final class RequestContext
{
    /** Most reliable client IP, or null when none could be determined. */
    public ?string $clientIp = null;

    /** SHA-256 over IP and stable request headers; identifies the client. */
    public ?string $fingerprint = null;

    /** Effective threat level (0–4), the max of global and individual. */
    public ?int $threatLevel = null;

    public ?int $threatLevelGlobal = null;

    public ?int $threatLevelIndividual = null;

    /** Requests from this fingerprint within the individual rate window. */
    public ?int $individualRate = null;

    /** Set for every crawler once VerifyCrawler ran; null for non-crawlers. */
    public ?CrawlerVerification $crawlerVerification = null;

    public ?Session $session = null;

    /**
     * Threat level the session's grant was issued for, 0 without a grant.
     * Grants from before levels were recorded (plain true) count as level 1.
     */
    public function grantLevel(): int
    {
        $grant = $this->session?->get('grant');

        return match (true) {
            \is_int($grant) => \max(0, $grant),
            $grant === true => 1,
            default => 0,
        };
    }

    /**
     * Threat level a challenge ticket or grant has to cover: the current
     * one, but at least 1. Not capped, so nothing covers level 4.
     */
    public function requiredLevel(): int
    {
        return \max(1, $this->threatLevel ?? 1);
    }

    /**
     * Whether the grant covers the current threat level. A grant issued at a
     * lower level than the current one does not: every escalation asks for
     * a new challenge.
     */
    public function isGrantSufficient(): bool
    {
        $level = $this->grantLevel();

        return $level > 0 && $level >= $this->requiredLevel();
    }
}
