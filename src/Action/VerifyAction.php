<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\ProofOfWork;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;
use GES\Botlock\Config\ProofOfWorkConfig;

/**
 * POST ?_botlock=verify — redeems a ticket with its proof-of-work solution
 * (body {"cid": …, "num": …, "sig": …, "slt": …, "exp": …, "alg": …}) and
 * grants the session for the ticket's threat level on success.
 *
 * The ticket is consumed whatever the outcome. It is rejected when its
 * interaction was not completed, when it is redeemed sooner than
 * BOTLOCK_MIN_SOLVE_MS after issuing, and with 409 "restart" when the
 * threat level rose above the ticket's in the meantime.
 */
final readonly class VerifyAction implements ActionHandlerInterface
{
    public function __construct(
        private ProofOfWorkConfig $config,
        private TicketService $tickets,
    ) {}

    /**
     * @throws JsonResponseException
     */
    public function handle(Request $request): Response
    {
        SessionNonce::assertMatches($request);

        if (!$data = $request->getJsonBody()) {
            throw new JsonResponseException('Invalid data', 400);
        }

        $ticket = $this->tickets->redeem($data['cid'] ?? null, $request);
        $now = $this->tickets->now();

        if (!$ticket->isReadyForProof()) {
            throw new JsonResponseException('Interaction required', 400);
        }

        if (($now - $ticket->issuedAt) * 1000 < $this->config->minSolveMs) {
            throw new JsonResponseException('Too fast', 400);
        }

        unset($data['cid'], $data['nonce']);

        $statusCode = 401;
        $session = $request->context->session;

        if ($ok = (new ProofOfWork($this->config))->verify($data, $ticket->subject, $ticket->binding()))
        {
            $session->set('grant', $ticket->level);
            SessionNonce::forget($request);
            $session->commit();
            $statusCode = 200;
        }

        return new JsonResponse($statusCode, ['ok' => $ok]);
    }
}
