<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;

/**
 * POST ?_botlock=verify — redeems a ticket with its proof-of-work solution
 * (body {"cid": …, "num": …, "sig": …, "slt": …, "exp": …, "alg": …}) and
 * grants the session for the ticket's threat level on success.
 *
 * The ticket is consumed whatever the outcome. It is rejected when its
 * interaction was not completed, when it is redeemed sooner than
 * BOTLOCK_MIN_SOLVE_MS after issuing, and with 409 "restart" when it
 * expired or the threat level rose above the ticket's in the meantime.
 */
final readonly class VerifyAction implements ChallengeStepInterface
{
    public function __construct(private TicketService $tickets) {}

    /**
     * @throws JsonResponseException
     */
    public function handle(Request $request): Response
    {
        if (!$data = $request->getJsonBody()) {
            throw new JsonResponseException('Invalid data', 400);
        }

        $ticket = $this->tickets->redeem($data['cid'] ?? null, $request);

        if (!$ticket->isReadyForProof()) {
            throw new JsonResponseException('Interaction required', 400);
        }

        $this->tickets->assertSolvedSlowly($ticket);

        $statusCode = 401;
        $session = $request->context->session;

        if ($ok = $this->tickets->verifyProof($ticket, $data, $ticket->binding()))
        {
            $session->set('grant', $ticket->level);
            $session->commit();
            $statusCode = 200;
        }

        return new JsonResponse($statusCode, ['ok' => $ok]);
    }
}
