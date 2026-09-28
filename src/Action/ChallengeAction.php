<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\InteractionCipher;
use GES\Botlock\Challenge\InteractionPolicy;
use GES\Botlock\Challenge\Interaction;
use GES\Botlock\Challenge\SliderPuzzle;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;
use GES\Botlock\Manager\BotTestManager;

/**
 * GET ?_botlock=challenge — issues a single-use challenge ticket for the
 * current threat level and remembers the client's nonce in the session.
 *
 * The answer names the required interaction. Only when none is required
 * does it carry the proof of work right away; otherwise the client gets it
 * from InteractAction once the interaction is done, and the answer carries
 * the key the client seals its interaction report with. For the slider it
 * also carries the puzzle images, whose target stays in the ticket.
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
        SessionNonce::remember($request);

        $isCrawler = $this->detective->isCrawler();
        $isTrustedGoodBot = $isCrawler && $this->detective->isTrustedGoodBot($request->context);

        // Level 4 is refused before any action runs; a challenge is always for 1–3.
        $level = \min(3, \max(1, $request->context->threatLevel ?? 1));
        $interaction = $this->policy->interaction($level, $isCrawler, $isTrustedGoodBot);
        $puzzle = $interaction === Interaction::Slider ? SliderPuzzle::create() : null;
        $now = $this->tickets->now();

        $ticket = new ChallengeTicket(
            id: ChallengeTicket::newId($now),
            subject: $request->context->fingerprint,
            level: $level,
            interaction: $interaction,
            issuedAt: $now,
            difficulty: $this->policy->difficulty($isCrawler, $isTrustedGoodBot),
            sliderTarget: $puzzle?->target,
            key: $interaction->isInteractive() ? InteractionCipher::newKey() : null,
        );

        $this->tickets->save($ticket);
        $this->tickets->maybeCollectGarbage();

        $data = $this->tickets->describe($ticket);

        if ($ticket->isReadyForProof()) {
            $data['pow'] = $this->tickets->proofOfWork($ticket, $ticket->binding(), $ticket->difficulty);
        }

        if ($ticket->key !== null) {
            $data['key'] = \base64_encode($ticket->key);
        }

        if ($puzzle) {
            $data['puzzle'] = $puzzle->toArray();
        }

        return new JsonResponse(200, $data);
    }
}
