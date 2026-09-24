<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\ChallengeTicketStore;
use GES\Botlock\Challenge\InteractionCipher;
use GES\Botlock\Challenge\InteractionPolicy;
use GES\Botlock\Challenge\Interaction;
use GES\Botlock\Challenge\ProofOfWork;
use GES\Botlock\Challenge\SliderPuzzle;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;
use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Config\ProofOfWorkConfig;

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
    /**
     * @param int           $gcProbability one request in this many sweeps expired tickets; 0 disables
     * @param \Closure|null $clock         returns the current Unix time with microseconds; defaults to microtime(true)
     */
    public function __construct(
        private BotTestManager $detective,
        private ProofOfWorkConfig $config,
        private ChallengeTicketStore $tickets,
        private InteractionPolicy $policy,
        private int $gcProbability = 1000,
        private ?\Closure $clock = null,
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
        $now = $this->now();

        $ticket = new ChallengeTicket(
            id: ChallengeTicket::newId(),
            subject: $request->context->fingerprint,
            level: $level,
            interaction: $interaction,
            issuedAt: $now,
            difficulty: $this->policy->difficulty($isCrawler, $isTrustedGoodBot),
            sliderTarget: $puzzle?->target,
            key: $interaction->isInteractive() ? InteractionCipher::newKey() : null,
        );

        if (!$this->tickets->save($ticket)) {
            throw new JsonResponseException('Challenge unavailable', 503);
        }

        $this->maybeCollectGarbage($now);

        $data = self::describe($ticket, $this->config);

        if ($ticket->isReadyForProof()) {
            $data['pow'] = self::proofOfWork($ticket, $this->config);
        }

        if ($ticket->key !== null) {
            $data['key'] = \base64_encode($ticket->key);
        }

        if ($puzzle) {
            $data['puzzle'] = $puzzle->toArray();
        }

        return new JsonResponse(200, $data);
    }

    /**
     * Public ticket fields: never the slider target, and not the key, which
     * only the challenge answer hands out.
     */
    public static function describe(ChallengeTicket $ticket, ProofOfWorkConfig $config): array
    {
        return [
            'cid' => $ticket->id,
            'lvl' => $ticket->level,
            'int' => $ticket->interaction->value,
            'exp' => $ticket->expiresAt(),
            'min_ms' => $config->minSolveMs,
        ];
    }

    /**
     * Proof of work bound to the ticket: its signature covers the ticket id,
     * level and interaction, and it expires with the ticket.
     */
    public static function proofOfWork(ChallengeTicket $ticket, ProofOfWorkConfig $config): array
    {
        return (new ProofOfWork($config))
            ->setDifficulty($ticket->difficulty)
            ->create($ticket->subject, $ticket->binding(), $ticket->expiresAt());
    }

    private function now(): float
    {
        return $this->clock ? ($this->clock)() : \microtime(true);
    }

    private function maybeCollectGarbage(float $now): void
    {
        if ($this->gcProbability > 0 && \random_int(1, $this->gcProbability) === 1) {
            // A minute of slack past the lifetime; mtime has second resolution.
            $this->tickets->collectGarbage($now - ChallengeTicket::TTL - 60);
        }
    }
}
