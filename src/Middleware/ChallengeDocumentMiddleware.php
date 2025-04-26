<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Http\Response\HtmlFileResponse;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

readonly class ChallengeDocumentMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Request $request,
        private string  $projectRoot,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->request->session->get('grant', false))
        {
            return $next($request);
        }

        $this->request->session->commit();

        return new HtmlFileResponse($this->projectRoot . '/assets/challenge.html', 401);
    }
}

/*

namespace GES\Botlock\Middleware;

use GES\Botlock\Manager\Config; // Add use
use GES\Botlock\Http\Response\HtmlFileResponse;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Manager\RateLimiter; // Add use
use GES\Botlock\Manager\Whitelist;  // Add use

readonly class ChallengeDocumentMiddleware implements MiddlewareInterface
{
    // Add dependencies to constructor
    public function __construct(
        private string      $projectRoot,
        private Config      $config,
        private Whitelist   $whitelist,
        private RateLimiter $rateLimiter,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        // 1. Check if already granted access via PoW
        if ($request->session->get('grant', false))
        {
            return $next($request); // Pass through to application
        }

        // Retrieve calculated levels from request bindings
        $globalThreatLevel = $request->threatLevel ?? 0;
        $individualRate = $request->individualRate ?? 0;

        // Get bot status (ensure Whitelist service is available - passed via constructor)
        $isKnownBot = $this->whitelist->isBot(); // Checks UA against CrawlerDetect
        $isGoodBot = $isKnownBot && $this->whitelist->isGoodBot(); // Checks specific 'good bot' list

        // --- Apply Threat Level Logic ---
        $action = 'challenge'; // Default action if not granted and not passed by Level 0

        switch ($globalThreatLevel) {
            case 3:
                // Highest level: Block known bad bots or very high traffic users
                if (($isKnownBot && !$isGoodBot)
                    || $individualRate > $this->config->getLevel3ThresholdIndividual()) // Use Config getter
                {
                    // error_log("Botlock: Blocking request due to Level 3. Bot: " . ($isKnownBot?'Y':'N') . ", Good: " . ($isGoodBot?'Y':'N') . ", Rate: $individualRate");
                    // Use the ErrorMiddleware helper to create a consistent response
                    return ErrorMiddleware::createErrorResponse($request, 403, 'Access Denied due to Threat Level');
                }
                // If not explicitly blocked, fall through to Level 2 behavior (challenge all)
                $action = 'challenge';
                break; // Break necessary if not falling through

            case 2:
                // Challenge *everyone* who isn't already granted
                $action = 'challenge';
                break;

            case 1:
                // Challenge only high-traffic individuals
                if ($individualRate > $this->config->getLevel1ThresholdIndividual()) // Use Config getter
                {
                    $action = 'challenge';
                } else {
                    // User rate is low, pass through despite global level 1
                    $action = 'pass';
                }
                break;

            case 0:
            default:
                // No threat, pass through
                $action = 'pass';
                break;
        }

        // --- Perform Action ---
        if ($action === 'pass') {
            // Allow request through to the main application
            return $next($request);
        } else { // action === 'challenge'
            // Commit session data needed for challenge (like nonce hash set by PoW middleware)
            $request->session->commit();
            // Show the challenge HTML page
            return new HtmlFileResponse($this->projectRoot . '/assets/challenge.html', 401);
        }
    }
}
*/