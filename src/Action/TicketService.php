<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\ChallengeTicketStore;
use GES\Botlock\Challenge\ProofOfWork;
use GES\Botlock\Config\ProofOfWorkConfig;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;

/**
 * What the challenge actions share about tickets: the clock, saving and
 * redeeming them, the fields the client may see and the proof of work
 * bound to them.
 */
final readonly class TicketService
{
    /**
     * @param int           $gcProbability one issued ticket in this many sweeps expired tickets; 0 disables
     * @param \Closure|null $clock         returns the current Unix time with microseconds; defaults to microtime(true)
     */
    public function __construct(
        private ChallengeTicketStore $tickets,
        private ProofOfWorkConfig $config,
        private int $gcProbability = 1000,
        private ?\Closure $clock = null,
    ) {}

    public function now(): float
    {
        return $this->clock ? ($this->clock)() : \microtime(true);
    }

    /**
     * @throws JsonResponseException 503 when the ticket could not be written
     */
    public function save(ChallengeTicket $ticket): void
    {
        if (!$this->tickets->save($ticket)) {
            throw new JsonResponseException('Challenge unavailable', 503);
        }
    }

    /**
     * Consumes the client's ticket and checks that it has not expired and
     * still covers the current threat level. A ticket issued to another
     * fingerprint is not found and stays untouched: ChallengeTicketStore
     * promises that, and every store is tested against it.
     *
     * An expired ticket asks for a restart like an escalation does, so a
     * page left open past its phase starts over instead of failing; once
     * the ticket has been swept, it is merely not found.
     *
     * @throws JsonResponseException
     */
    public function redeem(mixed $id, Request $request): ChallengeTicket
    {
        if (!ChallengeTicket::isValidId($id)
            || !($ticket = $this->tickets->consume((string) $request->context->fingerprint, $id)))
        {
            throw new JsonResponseException('Invalid challenge', 400);
        }

        if ($ticket->isExpired($this->now())
            || \max(1, $request->context->threatLevel ?? 1) > $ticket->level)
        {
            throw new JsonResponseException('restart', 409);
        }

        return $ticket;
    }

    /**
     * Public ticket fields: never the slider target, and not the key, which
     * only the answer that hands out the interaction carries.
     */
    public function describe(ChallengeTicket $ticket): array
    {
        return [
            'cid' => $ticket->id,
            'lvl' => $ticket->level,
            'int' => $ticket->interaction->value,
            'exp' => $ticket->expiresAt,
            'min_ms' => $this->config->minSolveMs,
        ];
    }

    /**
     * Proof of work bound to the ticket: its signature covers the subject
     * and $binding, and it expires with the ticket's current phase.
     */
    public function proofOfWork(ChallengeTicket $ticket, string $binding, float $difficulty): array
    {
        return (new ProofOfWork($this->config))
            ->setDifficulty($difficulty)
            ->create($ticket->subject, $binding, $ticket->expiresAt);
    }

    /**
     * Sweeps expired tickets on roughly one call in gcProbability.
     */
    public function maybeCollectGarbage(): void
    {
        if ($this->gcProbability > 0 && \random_int(1, $this->gcProbability) === 1) {
            // A minute of slack past the longest lifetime; renewed tickets
            // stay in their issue minute. Every call issues one ticket, so a
            // budget of twice the sweep interval outpaces them.
            $this->tickets->collectGarbage($this->now() - ChallengeTicket::MAX_LIFETIME - 60, 2 * $this->gcProbability);
        }
    }
}
