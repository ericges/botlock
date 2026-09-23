<?php declare(strict_types=1);

namespace GES\Botlock\Threat;

/**
 * Persistence for rate-limit state. Implementations know nothing about
 * threat levels or thresholds; they only store what they are given.
 *
 * Global state is an opaque associative array owned by the caller.
 * Individual state is a list of request timestamps per fingerprint.
 */
interface ThreatStateStore
{
    /**
     * Atomic read-modify-write of the global state. The reducer receives the
     * stored array (or an empty array when nothing is stored yet) and returns
     * the array to persist.
     *
     * @param callable(array): array $reducer
     * @return array|null the persisted state, or null when the lock or write failed
     */
    public function updateGlobal(callable $reducer): ?array;

    /**
     * @return array|null the stored global state, an empty array when nothing
     *                    is stored yet, or null when it could not be read
     */
    public function readGlobal(): ?array;

    /**
     * Appends $now to the fingerprint's timestamps and drops those older than $windowStart.
     *
     * @return bool false when the entry could not be written (lock timeout, I/O error)
     */
    public function recordIndividual(string $fingerprint, int $now, int $windowStart): bool;

    /**
     * @return int|null number of timestamps at or after $windowStart, or null when unreadable
     */
    public function countIndividual(string $fingerprint, int $windowStart): ?int;

    /**
     * Removes individual entries whose newest timestamp is older than $windowStart.
     *
     * @param int $maxEntries upper bound on entries inspected in one call, so a
     *                        single request never pays for a full sweep
     * @return int number of entries removed
     */
    public function collectGarbage(int $windowStart, int $maxEntries = 500): int;
}
