<?php declare(strict_types=1);

namespace GES\Botlock\Template;

/**
 * Best-effort on-disk cache for rendered challenge pages, one file per
 * language and version, stored in the state directory next to the secret
 * and rate-limit state. Every method degrades to "no cache" on I/O errors.
 */
final readonly class RenderedPageCache
{
    private const PREFIX = 'botlock_challenge_';

    public function __construct(
        private string $stateDir,
        private string $instanceId,
    ) {}

    /**
     * Cache key derived from the mtime and size of the given files, so an
     * edited template or translation (or an upgraded install) renders anew.
     */
    public static function versionOf(string ...$paths): string
    {
        $parts = [];

        foreach ($paths as $path) {
            $stat = @\stat($path);
            $parts[] = $stat ? $stat['mtime'] . ':' . $stat['size'] : 'missing';
        }

        return \substr(\sha1(\implode('|', $parts)), 0, 12);
    }

    /**
     * Path of the cached page, or null when it has not been rendered yet.
     */
    public function find(string $lang, string $version): ?string
    {
        $file = $this->file($lang, $version);

        return \is_file($file) ? $file : null;
    }

    /**
     * Writes the page atomically and removes stale versions of the same
     * language. Returns the cache path, or null when it could not be written.
     */
    public function store(string $lang, string $version, string $html): ?string
    {
        if (!@\is_dir($this->stateDir) && !@\mkdir($this->stateDir, 0775, true) && !@\is_dir($this->stateDir)) {
            return null;
        }

        $file = $this->file($lang, $version);
        $tmp = $file . '.' . \uniqid('', true) . '.tmp';

        if (@\file_put_contents($tmp, $html) !== \strlen($html) || !@\rename($tmp, $file)) {
            @\unlink($tmp);

            return null;
        }

        foreach (\glob($this->file($lang, '*')) ?: [] as $stale) {
            if ($stale !== $file) {
                @\unlink($stale);
            }
        }

        return $file;
    }

    private function file(string $lang, string $version): string
    {
        return $this->stateDir . \DIRECTORY_SEPARATOR . self::PREFIX . $this->instanceId . '_' . $lang . '_' . $version . '.html';
    }
}
