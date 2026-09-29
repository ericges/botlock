<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;

/**
 * GET ?_botlock=status — diagnostic view of how botlock sees this client.
 */
final readonly class StatusAction implements ActionHandlerInterface
{
    public function handle(Request $request): Response
    {
        $context = $request->context;

        return new JsonResponse(200, [
            'user_agent' => $request->getHeader('User-Agent'),
            'subject' => $context->fingerprint,
            'threat_level' => $context->threatLevel,
            'threat_level_global' => $context->threatLevelGlobal,
            'threat_level_individual' => $context->threatLevelIndividual,
            'individual_rate' => $context->individualRate,
            'crawler_verification' => $context->crawlerVerification?->value,
            'passed' => $context->isGrantSufficient(),
            'grant_level' => $context->grantLevel(),
        ]);
    }
}
