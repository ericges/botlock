<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Crawler\CrawlerVerification;
use GES\Botlock\Crawler\CrawlerVerifier;
use GES\Botlock\Crawler\DnsCrawlerVerifier;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Manager\BotTestManager;

/**
 * Records on the request context whether a crawler is who it claims to be.
 *
 * The verifier is chosen from the providers in BOTLOCK_VERIFY_BOTS whose
 * crawler names match the detected crawler, so the check does not depend on
 * the User-Agent's casing. A failed verification raises the effective threat
 * level to at least 2; a successful one changes no level, the verification
 * state alone decides whether ThreatPassMiddleware may exempt the bot.
 */
final readonly class VerifyCrawlerMiddleware implements MiddlewareInterface
{
    private CrawlerVerifier $verifier;

    public function __construct(
        private BotTestManager $detective,
        private DetectionConfig $config,
        ?CrawlerVerifier $verifier = null,
    ) {
        $this->verifier = $verifier ?? new DnsCrawlerVerifier();
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->detective->isCrawler()) {
            return $next($request);
        }

        $context = $request->context;
        $provider = $this->detective->getVerifyProvider();

        if ($provider === null || !$this->config->dnsChecks) {
            $context->crawlerVerification = CrawlerVerification::NotApplicable;

            return $next($request);
        }

        if (!$ip = $context->clientIp) {
            $context->crawlerVerification = CrawlerVerification::Unverified;

            return $next($request);
        }

        $context->crawlerVerification = $this->verifier->verify($provider, $ip);

        if ($context->crawlerVerification === CrawlerVerification::Failed) {
            $context->threatLevel = \max(2, $context->threatLevel ?? 0);
        }

        return $next($request);
    }
}
