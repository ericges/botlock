<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Support;

use GES\Botlock\Threat\ThreatStateStore;

/**
 * Test double: keeps state in arrays and can simulate an unavailable store,
 * either wholesale ($failing) or for individual reads/writes only.
 */
final class InMemoryThreatStateStore implements ThreatStateStore
{
    public array $global = [];

    /** @var array<string, int[]> */
    public array $individual = [];

    /** @var list<array{windowStart:int,maxEntries:int}> */
    public array $gcCalls = [];

    public function __construct(
        public bool $failing = false,
        public bool $failIndividualRead = false,
        public bool $failIndividualWrite = false,
        public bool $failGlobalWrite = false,
    ) {}

    public function updateGlobal(callable $reducer): ?array
    {
        if ($this->failing || $this->failGlobalWrite) {
            return null;
        }

        return $this->global = $reducer($this->global);
    }

    public function readGlobal(): ?array
    {
        return $this->failing ? null : $this->global;
    }

    public function recordIndividual(string $fingerprint, int $now, int $windowStart): bool
    {
        if ($this->failing || $this->failIndividualWrite) {
            return false;
        }

        $kept = \array_values(\array_filter($this->individual[$fingerprint] ?? [], static fn(int $ts): bool => $ts >= $windowStart));
        $kept[] = $now;
        $this->individual[$fingerprint] = $kept;

        return true;
    }

    public function countIndividual(string $fingerprint, int $windowStart): ?int
    {
        $timestamps = $this->individualTimestamps($fingerprint, $windowStart);

        return $timestamps === null ? null : \count($timestamps);
    }

    public function individualTimestamps(string $fingerprint, int $windowStart): ?array
    {
        if ($this->failing || $this->failIndividualRead) {
            return null;
        }

        $timestamps = \array_values(\array_filter($this->individual[$fingerprint] ?? [], static fn(int $ts): bool => $ts >= $windowStart));
        \sort($timestamps);

        return $timestamps;
    }

    public function collectGarbage(int $windowStart, int $maxEntries = 500): int
    {
        $this->gcCalls[] = ['windowStart' => $windowStart, 'maxEntries' => $maxEntries];

        $removed = 0;
        foreach ($this->individual as $fingerprint => $timestamps) {
            if (\max($timestamps ?: [0]) < $windowStart) {
                unset($this->individual[$fingerprint]);
                $removed++;
            }
        }

        return $removed;
    }
}
