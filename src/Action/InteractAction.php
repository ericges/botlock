<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\Interaction;
use GES\Botlock\Challenge\InteractionCipher;
use GES\Botlock\Challenge\InteractionPolicy;
use GES\Botlock\Challenge\SliderPuzzle;
use GES\Botlock\Challenge\SliderTrack;
use GES\Botlock\Challenge\SliderVerdict;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;

/**
 * POST ?_botlock=challenge — reports the completed interaction of a ticket
 * (for the slider once PuzzleAction rendered its puzzle; body {"cid": …,
 * "iv": …, "ct": …}: the report sealed with the ticket's key, see
 * InteractionCipher; for the slider it holds "pos" and "track")
 * and answers with its proof of work. A report that does not open, a
 * slider offset outside the tolerance or a track SliderTrack rejects all
 * answer the same 403 "retry"; the ticket is gone either way, so every
 * guess costs a new challenge. A slider moved by keys instead of a drag
 * gets a harder proof of work.
 *
 * The ticket is consumed and stored again as interacted with a fresh
 * deadline for the proof, so a concurrent verify of the same ticket cannot
 * slip in between. A threat level that rose above the ticket's since it was
 * issued answers 409 "restart": the client has to fetch a new challenge for
 * the higher level.
 */
final readonly class InteractAction implements ChallengeStepInterface
{
    public function __construct(
        private TicketService $tickets,
        private InteractionPolicy $policy,
    ) {}

    /**
     * @throws JsonResponseException
     */
    public function handle(Request $request): Response
    {
        SessionNonce::assertMatches($request);

        $data = $request->getJsonBody() ?? [];
        $ticket = $this->tickets->redeem($data['cid'] ?? null, $request);
        $now = $this->tickets->now();

        if (!$ticket->interaction->isInteractive() || $ticket->interacted || $ticket->awaitsPuzzle()) {
            throw new JsonResponseException('Invalid challenge', 400);
        }

        $report = $ticket->key === null ? null : InteractionCipher::open($ticket->key, $ticket->id, $data['iv'] ?? null, $data['ct'] ?? null);

        $verdict = match (true) {
            $report === null => SliderVerdict::Rejected,
            // From the render: time at the gate was spent before there was a picture.
            $ticket->interaction === Interaction::Slider => self::judgeSlider($ticket, $report, ($now - ($ticket->puzzleAt ?? $ticket->issuedAt)) * 1000),
            default => null,
        };

        if ($verdict === SliderVerdict::Rejected) {
            throw new JsonResponseException('retry', 403);
        }

        // The proof gets a phase of its own, however long the interaction took.
        $ticket = $ticket->withInteracted(
            $verdict === SliderVerdict::Assisted ? $ticket->difficulty * $this->policy->assistedFactor() : null,
        )->renewed($now);

        $this->tickets->save($ticket);

        return new JsonResponse(200, $this->tickets->describe($ticket) + [
            'pow' => $this->tickets->proofOfWork($ticket, $ticket->binding(), $ticket->difficulty),
        ]);
    }

    private static function judgeSlider(ChallengeTicket $ticket, array $report, float $elapsedMs): SliderVerdict
    {
        $pos = $report['pos'] ?? null;

        if ($ticket->sliderTarget === null
            || !\is_int($pos)
            || !SliderPuzzle::accepts($pos, $ticket->sliderTarget)
            || !($track = SliderTrack::fromArray($report['track'] ?? null)))
        {
            return SliderVerdict::Rejected;
        }

        return $track->judge($pos, $elapsedMs);
    }
}
