<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\InteractionCipher;
use GES\Botlock\Challenge\PuzzleBudget;
use GES\Botlock\Challenge\PuzzleBudgetResult;
use GES\Botlock\Challenge\SliderPuzzle;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;

/**
 * POST ?_botlock=puzzle — pays for a level-3 slider puzzle with the gate
 * proof of work of its ticket (body {"cid": …, "num": …, "sig": …, "slt": …,
 * "exp": …, "alg": …}) and answers with the puzzle images and the key the
 * client seals its slider report with.
 *
 * The ticket is consumed first and only stored again with its puzzle, so a
 * wrong gate solution (401), a used-up client budget (429) or a full minute
 * for all clients (503, both with Retry-After) spends it. Each render
 * counts against PuzzleBudget whatever the slider later does, so every
 * guess costs a gate proof of work and a render of the client's budget.
 * The ticket gets a fresh deadline for the slider, and the slider is timed
 * from the render.
 */
final readonly class PuzzleAction implements ChallengeStepInterface
{
    public function __construct(
        private TicketService $tickets,
        private PuzzleBudget $budget,
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

        if (!$ticket->awaitsPuzzle()) {
            throw new JsonResponseException('Invalid challenge', 400);
        }

        if (!$this->tickets->verifyProof($ticket, $data, $ticket->gateBinding())) {
            return new JsonResponse(401, ['ok' => false]);
        }

        $context = $request->context;
        $result = $this->budget->reserve($context->clientIp, (string) $context->fingerprint);

        if ($result !== PuzzleBudgetResult::Granted) {
            throw self::refusal($result, $this->budget->retryAfter($result, $context->clientIp, (string) $context->fingerprint));
        }

        // Rendered before reading the clock, so puzzleAt is the moment the picture exists.
        $puzzle = SliderPuzzle::create();
        $now = $this->tickets->now();
        $ticket = $ticket->withPuzzle($puzzle->target, InteractionCipher::newKey(), $now)->renewed($now);

        $this->tickets->save($ticket);

        return new JsonResponse(200, $this->tickets->describe($ticket) + [
            'key' => \base64_encode($ticket->key),
            'puzzle' => $puzzle->toArray(),
        ]);
    }

    private static function refusal(PuzzleBudgetResult $result, int $retryAfter): JsonResponseException
    {
        [$status, $error] = match ($result) {
            PuzzleBudgetResult::ClientExhausted => [429, 'Too Many Requests'],
            PuzzleBudgetResult::GlobalExhausted => [503, 'Busy'],
        };

        return new JsonResponseException($error, $status, headers: ['Retry-After' => (string) $retryAfter]);
    }
}
