<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\ChallengeTicketStore;
use GES\Botlock\Challenge\Interaction;
use GES\Botlock\Challenge\InteractionCipher;
use GES\Botlock\Challenge\SliderPuzzle;
use GES\Botlock\Config\ProofOfWorkConfig;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;

/**
 * POST ?_botlock=challenge — reports the completed interaction of a ticket
 * (body {"cid": …, "iv": …, "ct": …}: the report sealed with the ticket's
 * key, see InteractionCipher; for the slider it holds "pos") and answers
 * with its proof of work. A report that does not open or a slider offset
 * outside the tolerance answers 403 "retry"; the ticket is gone either way,
 * so every guess costs a new challenge.
 *
 * The ticket is consumed and stored again as interacted, so a concurrent
 * verify of the same ticket cannot slip in between. A threat level that
 * rose above the ticket's since it was issued answers 409 "restart": the
 * client has to fetch a new challenge for the higher level.
 */
final readonly class InteractAction implements ActionHandlerInterface
{
    /**
     * @param \Closure|null $clock returns the current Unix time with microseconds; defaults to microtime(true)
     */
    public function __construct(
        private ProofOfWorkConfig $config,
        private ChallengeTicketStore $tickets,
        private ?\Closure $clock = null,
    ) {}

    /**
     * @throws JsonResponseException
     */
    public function handle(Request $request): Response
    {
        SessionNonce::assertMatches($request);

        $data = $request->getJsonBody() ?? [];
        $ticket = self::redeem($this->tickets, $data['cid'] ?? null, $request, $this->now());

        if (!$ticket->interaction->isInteractive() || $ticket->interacted) {
            throw new JsonResponseException('Invalid challenge', 400);
        }

        $report = $ticket->key === null ? null : InteractionCipher::open($ticket->key, $ticket->id, $data['iv'] ?? null, $data['ct'] ?? null);

        if ($report === null
            || ($ticket->interaction === Interaction::Slider
                && ($ticket->sliderTarget === null || !SliderPuzzle::accepts($report['pos'] ?? null, $ticket->sliderTarget))))
        {
            throw new JsonResponseException('retry', 403);
        }

        $ticket = $ticket->withInteracted();

        if (!$this->tickets->save($ticket)) {
            throw new JsonResponseException('Challenge unavailable', 503);
        }

        return new JsonResponse(200, ChallengeAction::describe($ticket, $this->config) + [
            'pow' => ChallengeAction::proofOfWork($ticket, $this->config),
        ]);
    }

    /**
     * Consumes the ticket and checks that it belongs to this client, has not
     * expired and still covers the current threat level.
     *
     * @throws JsonResponseException
     */
    public static function redeem(ChallengeTicketStore $tickets, mixed $id, Request $request, float $now): ChallengeTicket
    {
        if (!ChallengeTicket::isValidId($id)
            || !($ticket = $tickets->consume($id))
            || !\hash_equals($ticket->subject, (string) $request->context->fingerprint)
            || $ticket->isExpired($now))
        {
            throw new JsonResponseException('Invalid challenge', 400);
        }

        if (\max(1, $request->context->threatLevel ?? 1) > $ticket->level) {
            throw new JsonResponseException('restart', 409);
        }

        return $ticket;
    }

    private function now(): float
    {
        return $this->clock ? ($this->clock)() : \microtime(true);
    }
}
