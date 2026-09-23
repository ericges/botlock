<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

/**
 * Persistence for issued challenge tickets.
 */
interface ChallengeTicketStore
{
    /**
     * Stores the ticket, replacing one with the same id. False when it
     * could not be written.
     */
    public function save(ChallengeTicket $ticket): bool;

    /**
     * Removes and returns the ticket. Of several concurrent callers at most
     * one receives it, which makes every ticket single-use.
     */
    public function consume(string $id): ?ChallengeTicket;

    /**
     * Deletes tickets issued before $issuedBefore; returns how many.
     */
    public function collectGarbage(float $issuedBefore): int;
}
