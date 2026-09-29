<?php declare(strict_types=1);

namespace GES\Botlock\Threat;

/**
 * Opens a store on its first use instead of at boot, for a store most
 * requests never touch. When opening fails, the error is logged via
 * error_log() once and every call answers as a failed store would, so the
 * caller's own failure handling decides instead of the whole request
 * failing. The failure is kept for the rest of the request.
 */
final class LazyThreatStateStore implements ThreatStateStore
{
    private ?ThreatStateStore $store = null;
    private bool $failed = false;

    /**
     * @param \Closure(): ThreatStateStore $open
     */
    public function __construct(private readonly \Closure $open) {}

    public function updateGlobal(callable $reducer): ?array
    {
        return $this->store()?->updateGlobal($reducer);
    }

    public function readGlobal(): ?array
    {
        return $this->store()?->readGlobal();
    }

    public function recordIndividual(string $fingerprint, int $now, int $windowStart): bool
    {
        return $this->store()?->recordIndividual($fingerprint, $now, $windowStart) ?? false;
    }

    public function countIndividual(string $fingerprint, int $windowStart): ?int
    {
        return $this->store()?->countIndividual($fingerprint, $windowStart);
    }

    public function individualTimestamps(string $fingerprint, int $windowStart): ?array
    {
        return $this->store()?->individualTimestamps($fingerprint, $windowStart);
    }

    public function collectGarbage(int $windowStart, int $maxEntries = 500): int
    {
        return $this->store()?->collectGarbage($windowStart, $maxEntries) ?? 0;
    }

    private function store(): ?ThreatStateStore
    {
        if ($this->store === null && !$this->failed) {
            try {
                $this->store = ($this->open)();
            } catch (\RuntimeException $exception) {
                $this->failed = true;
                \error_log('Botlock: state store unavailable: ' . $exception->getMessage());
            }
        }

        return $this->store;
    }
}
