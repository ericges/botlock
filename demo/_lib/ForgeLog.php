<?php declare(strict_types=1);

namespace GES\Botlock\Demo;

/**
 * The last exchanges of the demo relay, newest last, kept in one JSON
 * file under .demo/ and rewritten under an exclusive lock.
 */
final readonly class ForgeLog
{
    public const SIZE = 50;

    public function __construct(private string $file) {}

    /**
     * @param array<string, mixed> $entry
     */
    public function append(array $entry): void
    {
        $this->locked(function (array $entries) use ($entry): array {
            $entries[] = $entry;

            return \array_slice($entries, -self::SIZE);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $json = @\file_get_contents($this->file);
        $entries = \is_string($json) ? \json_decode($json, true) : null;

        return \is_array($entries) ? \array_values($entries) : [];
    }

    public function clear(): void
    {
        $this->locked(static fn(): array => []);
    }

    /**
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $change
     */
    private function locked(callable $change): void
    {
        $dir = \dirname($this->file);

        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            return;
        }

        if (!$handle = @\fopen($this->file, 'c+')) {
            return;
        }

        try
        {
            if (!\flock($handle, \LOCK_EX)) {
                return;
            }

            $entries = \json_decode((string) \stream_get_contents($handle), true);
            $entries = $change(\is_array($entries) ? \array_values($entries) : []);

            \ftruncate($handle, 0);
            \rewind($handle);
            \fwrite($handle, (string) \json_encode($entries, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE));
            \fflush($handle);
            \flock($handle, \LOCK_UN);
        }
        finally
        {
            \fclose($handle);
        }
    }
}
