<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Support;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\ChallengeTicketStore;

/**
 * Test double: keeps tickets in an array and can refuse writes.
 */
final class InMemoryChallengeTicketStore implements ChallengeTicketStore
{
    /** @var array<string, ChallengeTicket> */
    public array $tickets = [];

    /** @var list<float> */
    public array $gcCalls = [];

    public function __construct(public bool $failing = false) {}

    public function save(ChallengeTicket $ticket): bool
    {
        if ($this->failing) {
            return false;
        }

        $this->tickets[$ticket->id] = $ticket;

        return true;
    }

    public function consume(string $id): ?ChallengeTicket
    {
        $ticket = $this->tickets[$id] ?? null;
        unset($this->tickets[$id]);

        return $ticket;
    }

    public function collectGarbage(float $issuedBefore): int
    {
        $this->gcCalls[] = $issuedBefore;
        $before = \count($this->tickets);
        $this->tickets = \array_filter($this->tickets, static fn(ChallengeTicket $t): bool => $t->issuedAt >= $issuedBefore);

        return $before - \count($this->tickets);
    }
}
