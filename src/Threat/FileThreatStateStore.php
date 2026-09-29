<?php declare(strict_types=1);

namespace GES\Botlock\Threat;

use GES\Botlock\Filesystem\PrivateDirectory;

/**
 * Stores global state as one JSON file per instance and individual state
 * as one newline-separated timestamp file per fingerprint, sharded by the
 * first two fingerprint characters. Access is serialized with flock().
 * Directories and files are kept owner-only.
 */
final class FileThreatStateStore implements ThreatStateStore
{
    private const INDIVIDUAL_DIR = 'ua';
    private const INDIVIDUAL_EXT = '.lst';
    private const LOCK_TIMEOUT_MS = 100;
    private const LOCK_RETRY_US = 5000;

    public function __construct(
        private readonly string $stateDir,
        private readonly string $instanceId,
    ) {
        PrivateDirectory::ensure($this->stateDir);

        if (!\is_writable($this->stateDir)) {
            throw new \RuntimeException("State directory '{$this->stateDir}' is not writable.");
        }
    }

    public function updateGlobal(callable $reducer): ?array
    {
        $path = $this->globalFile();
        $isNew = !\is_file($path);

        $state = $this->withLock($path, 'c+', function ($file) use ($reducer): array {
            try {
                $state = \json_decode((string) \stream_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                $state = \is_array($state) ? $state : [];
            } catch (\JsonException) {
                $state = [];
            }
            $state = $reducer($state);

            $encoded = \json_encode($state, \JSON_THROW_ON_ERROR);

            if (!\ftruncate($file, 0) || !\rewind($file) || \fwrite($file, $encoded) !== \strlen($encoded)) {
                throw new \RuntimeException('Could not persist global threat state.');
            }

            return $state;
        });

        if ($isNew && $state !== null) {
            PrivateDirectory::restrictFile($path);
        }

        return $state;
    }

    public function readGlobal(): ?array
    {
        $path = $this->globalFile();

        if (!\is_file($path)) {
            return [];
        }

        return $this->withLock($path, 'r', function ($file): array {
            $state = \json_decode((string) \stream_get_contents($file), true);

            return \is_array($state) ? $state : [];
        });
    }

    public function recordIndividual(string $fingerprint, int $now, int $windowStart): bool
    {
        $path = $this->individualFile($fingerprint);

        try {
            PrivateDirectory::ensure(\dirname($path));
        } catch (\RuntimeException) {
            return false;
        }

        $isNew = !\is_file($path);

        $written = $this->withLock($path, 'c+', function ($file) use ($now, $windowStart): bool {
            $timestamps = $this->readTimestamps($file, $windowStart);
            $timestamps[] = $now;

            $encoded = \implode("\n", $timestamps) . "\n";

            if (!\ftruncate($file, 0) || !\rewind($file) || \fwrite($file, $encoded) !== \strlen($encoded)) {
                throw new \RuntimeException('Could not persist individual threat state.');
            }

            return true;
        });

        if ($isNew && $written) {
            PrivateDirectory::restrictFile($path);
        }

        return $written === true;
    }

    public function countIndividual(string $fingerprint, int $windowStart): ?int
    {
        $timestamps = $this->individualTimestamps($fingerprint, $windowStart);

        return $timestamps === null ? null : \count($timestamps);
    }

    public function individualTimestamps(string $fingerprint, int $windowStart): ?array
    {
        $path = $this->individualFile($fingerprint);

        if (!\is_file($path)) {
            return [];
        }

        return $this->withLock($path, 'r', fn($file): array => $this->readTimestamps($file, $windowStart));
    }

    /**
     * {@inheritDoc}
     *
     * Shards are visited round-robin from a random start, so successive
     * budget-limited sweeps reach every shard instead of always inspecting
     * the same first entries.
     *
     * @param int|null $startShard index into the sorted shard list to begin with; random when null
     */
    public function collectGarbage(int $windowStart, int $maxEntries = 500, ?int $startShard = null): int
    {
        $root = $this->stateDir . \DIRECTORY_SEPARATOR . self::INDIVIDUAL_DIR;
        $shards = \glob($root . \DIRECTORY_SEPARATOR . '*', \GLOB_ONLYDIR) ?: [];
        $count = \count($shards);

        if ($count === 0) {
            return 0;
        }

        $start = $startShard === null ? \random_int(0, $count - 1) : (($startShard % $count) + $count) % $count;
        $removed = 0;
        $seen = 0;
        $touched = [];

        for ($i = 0; $i < $count && $seen < $maxEntries; $i++)
        {
            $shard = $shards[($start + $i) % $count];

            try {
                $entries = new \FilesystemIterator($shard, \FilesystemIterator::SKIP_DOTS);
            } catch (\UnexpectedValueException) {
                continue; // shard vanished between glob() and open
            }

            /** @var \SplFileInfo $entry */
            foreach ($entries as $entry)
            {
                if ($seen++ >= $maxEntries) {
                    break;
                }

                if (!$entry->isFile() || !\str_ends_with($entry->getFilename(), self::INDIVIDUAL_EXT)) {
                    continue;
                }

                // Files are rewritten on every hit, so mtime is the newest timestamp.
                if ($entry->getMTime() < $windowStart && @\unlink($entry->getPathname())) {
                    $removed++;
                    $touched[$shard] = true;
                }
            }

            unset($entries); // release the directory handle before rmdir()
        }

        foreach (\array_keys($touched) as $shard) {
            @\rmdir($shard); // only succeeds when empty
        }

        return $removed;
    }

    /**
     * @return int[] timestamps at or after $windowStart, in file order
     */
    private function readTimestamps($file, int $windowStart): array
    {
        $timestamps = [];

        while (($line = \fgets($file)) !== false)
        {
            $ts = (int) \trim($line);

            if ($ts >= $windowStart) {
                $timestamps[] = $ts;
            }
        }

        return $timestamps;
    }

    /**
     * Runs $callback with the file handle while holding a lock: shared for
     * read mode, exclusive otherwise. Returns null when the file could not be
     * opened, the lock was not acquired in time, or the callback threw.
     *
     * @template T
     * @param callable(resource): T $callback
     * @return T|null
     */
    private function withLock(string $path, string $mode, callable $callback): mixed
    {
        if (!$file = @\fopen($path, $mode)) {
            return null;
        }

        $lockType = $mode === 'r' ? \LOCK_SH : \LOCK_EX;
        $deadline = \microtime(true) + self::LOCK_TIMEOUT_MS / 1000;
        $locked = false;

        do {
            if (\flock($file, $lockType | \LOCK_NB)) {
                $locked = true;
                break;
            }

            \usleep(self::LOCK_RETRY_US);
        } while (\microtime(true) < $deadline);

        $result = null;

        if ($locked)
        {
            try {
                $result = $callback($file);

                if ($mode !== 'r') {
                    \fflush($file);
                }
            } catch (\Throwable) {
                $result = null;
            } finally {
                \flock($file, \LOCK_UN);
            }
        }

        \fclose($file);

        return $result;
    }

    private function individualFile(string $fingerprint): string
    {
        return $this->stateDir
            . \DIRECTORY_SEPARATOR . self::INDIVIDUAL_DIR
            . \DIRECTORY_SEPARATOR . \substr($fingerprint, 0, 2)
            . \DIRECTORY_SEPARATOR . $fingerprint . self::INDIVIDUAL_EXT;
    }

    private function globalFile(): string
    {
        return $this->stateDir . \DIRECTORY_SEPARATOR . 'botlock_state_' . $this->instanceId . '.json';
    }
}
