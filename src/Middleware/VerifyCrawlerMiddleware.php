<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Manager\ConfigManager;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\VerifyBot;

readonly class VerifyCrawlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private BotTestManager $detective,
        private ConfigManager  $config,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if (!$this->detective->isCrawler() || !$this->config->getDnsChecks()) {
            return $next($request);
        }

        $ip = $request->context->clientIp;
        $verifyBots = $this->config->getVerifyBots();
        $userAgent = $request->getHeader('User-Agent');

        if (!$ip || !$userAgent || !$verifyBots) {
            return $next($request);
        }

        $verified = match (true) {
            isset($verifyBots['google']) && \str_contains($userAgent, 'google') => VerifyBot::google($ip),
            default => null,
        };

        $newThreatLevel = match ($verified) {
            true => \max(($request->context->threatLevel ?? 0) - 1, 0),  // reduce by 1
            false => \max(($request->context->threatLevel ?? 0) + 1, 2), // set to 2 or higher
            default => null,
        };

        if (\is_int($newThreatLevel)) {
            $request->context->threatLevel = $newThreatLevel;
            $request->context->threatLevelIndividual = $newThreatLevel;
        }

        return $next($request);
    }
}