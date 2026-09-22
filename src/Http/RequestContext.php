<?php declare(strict_types=1);

namespace GES\Botlock\Http;

/**
 * Per-request state shared between middlewares.
 *
 * Populated progressively while the request travels through the middleware
 * stack: WhoIs sets the client identity, ThreatEvaluation and VerifyCrawler
 * the threat levels, Session the session. Properties are null until the
 * responsible middleware has run.
 */
final class RequestContext
{
    /** Most reliable client IP, or null when none could be determined. */
    public ?string $clientIp = null;

    /** SHA-256 over IP and stable request headers; identifies the client. */
    public ?string $fingerprint = null;

    /** Effective threat level (0–3), the max of global and individual. */
    public ?int $threatLevel = null;

    public ?int $threatLevelGlobal = null;

    public ?int $threatLevelIndividual = null;

    /** Requests from this fingerprint within the individual rate window. */
    public ?int $individualRate = null;

    public ?Session $session = null;
}
