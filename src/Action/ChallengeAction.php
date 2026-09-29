<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\InteractionCipher;
use GES\Botlock\Challenge\InteractionPolicy;
use GES\Botlock\Challenge\Interaction;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;
use GES\Botlock\Manager\BotTestManager;

/**
 * GET ?_botlock=challenge — issues a single-use challenge ticket for the
 * current threat level, stored with the hash of the client's nonce.
 *
 * The answer names the required interaction. Only when none is required
 * does it carry the proof of work right away; otherwise the client gets it
 * from InteractAction once the interaction is done. A click ticket carries
 * the key the client seals its report with. A slider ticket starts at the
 * gate: the answer carries a proof of work at base difficulty, which
 * PuzzleAction takes in exchange for the puzzle and its key, so a bare
 * request never costs the server a picture.
 */
final readonly class ChallengeAction implements ActionHandlerInterface
{
    public function __construct(
        private BotTestManager $detective,
        private InteractionPolicy $policy,
        private TicketService $tickets,
    ) {}

    /**
     * @throws JsonResponseException
     */
    public function handle(Request $request): Response
    {
        $isCrawler = $this->detective->isCrawler();
        $isTrustedGoodBot = $isCrawler && $this->detective->isTrustedGoodBot($request->context);

        // Level 4 is refused before any action runs; a challenge is always for 1–3.
        $level = \min(3, $request->context->requiredLevel());
        $interaction = $this->policy->interaction($level, $isCrawler, $isTrustedGoodBot);
        $now = $this->tickets->now();

        $ticket = new ChallengeTicket(
            id: ChallengeTicket::newId($now),
            subject: $request->context->fingerprint,
            nonceHash: ChallengeNonce::hash($request),
            level: $level,
            interaction: $interaction,
            issuedAt: $now,
            expiresAt: (int) \floor($now) + ChallengeTicket::TTL,
            difficulty: $this->policy->difficulty($isCrawler, $isTrustedGoodBot),
            // The slider's key comes with its puzzle, see PuzzleAction.
            key: $interaction === Interaction::Click ? InteractionCipher::newKey() : null,
        );

        $this->tickets->save($ticket);
        $this->tickets->maybeCollectGarbage();

        $data = $this->tickets->describe($ticket);

        if ($ticket->isReadyForProof()) {
            $data['pow'] = $this->tickets->proofOfWork($ticket, $ticket->binding(), $ticket->difficulty);
        }

        if ($ticket->awaitsPuzzle()) {
            // Base difficulty for everyone: the gate pays for the picture, the final proof for the grant.
            $data['gate'] = $this->tickets->proofOfWork($ticket, $ticket->gateBinding(), 1.0);
        }

        if ($ticket->key !== null) {
            $data['key'] = \base64_encode($ticket->key);
        }

        return new JsonResponse(200, $data);
    }
}
