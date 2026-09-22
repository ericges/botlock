<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;
use GES\Botlock\Http\Response\RedirectResponse;

/**
 * POST ?_botlock=reset — clears the session. Redirects to the posted
 * "location" (same origin only) when given, otherwise answers JSON.
 */
final readonly class ResetAction implements ActionHandlerInterface
{
    public function handle(Request $request): Response
    {
        $request->context->session->clear();
        $request->context->session->commit();

        if (\is_string($location = $request->getPost('location'))
            && $location !== ''
            && \filter_var($location = $request->getAbsoluteUrl($location), \FILTER_VALIDATE_URL))
        {
            return new RedirectResponse($location);
        }

        return new JsonResponse(200, [
            'ok' => true,
            'message' => 'Session cleared',
        ]);
    }
}
