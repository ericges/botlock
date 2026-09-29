<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Support;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\ChallengeTicketStore;

/**
 * Test double: keeps tickets in an array keyed like the file store, by
 * subject and id, and can refuse writes.
 */
final class InMemoryChallengeTicketStore implements ChallengeTicketStore
{
    /** @var array<string, ChallengeTicket> keyed by subject . "\0" . id */
    public array $tickets = [];

    /** @var list<array{float, int}> issuedBefore and maxEntries of each sweep */
    public array $gcCalls = [];

    public function __construct(public bool $failing = false) {}

    public function save(ChallengeTicket $ticket): bool
    {
        if ($this->failing) {
            return false;
        }

        $this->tickets[$ticket->subject . "\0" . $ticket->id] = $ticket;

        return true;
    }

    public function consume(string $subject, string $id): ?ChallengeTicket
    {
        $key = $subject . "\0" . $id;
        $ticket = $this->tickets[$key] ?? null;
        unset($this->tickets[$key]);

        return $ticket;
    }

    /**
     * The stored ticket with this id, whoever it was issued to; for
     * assertions only, it does not consume.
     */
    public function find(string $id): ?ChallengeTicket
    {
        foreach ($this->tickets as $ticket) {
            if ($ticket->id === $id) {
                return $ticket;
            }
        }

        return null;
    }

    public function collectGarbage(float $issuedBefore, int $maxEntries): int
    {
        $this->gcCalls[] = [$issuedBefore, $maxEntries];
        $removed = 0;

        foreach ($this->tickets as $id => $ticket) {
            if ($removed < $maxEntries && $ticket->issuedAt < $issuedBefore) {
                unset($this->tickets[$id]);
                $removed++;
            }
        }

        return $removed;
    }
}
