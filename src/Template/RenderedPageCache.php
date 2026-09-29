<?php declare(strict_types=1);

namespace GES\Botlock\Template;

use GES\Botlock\Filesystem\PrivateDirectory;

/**
 * Best-effort on-disk cache for rendered pages, one file per page, language
 * and version, stored in the state directory next to the secret and
 * rate-limit state. Every method degrades to "no cache" on I/O errors.
 */
final readonly class RenderedPageCache
{
    private const PREFIX = 'botlock_';

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
    public function find(string $page, string $lang, string $version): ?string
    {
        $file = $this->file($page, $lang, $version);

        return \is_file($file) ? $file : null;
    }

    /**
     * Writes the page atomically and removes stale versions of the same
     * page and language. Returns the cache path, or null when it could not
     * be written.
     */
    public function store(string $page, string $lang, string $version, string $html): ?string
    {
        $file = $this->file($page, $lang, $version);

        try {
            PrivateDirectory::ensure($this->stateDir);
        } catch (\RuntimeException) {
            return null;
        }

        $tmp = $file . '.' . \uniqid('', true) . '.tmp';

        if (@\file_put_contents($tmp, $html) !== \strlen($html)) {
            @\unlink($tmp);

            return null;
        }

        PrivateDirectory::restrictFile($tmp);

        if (!@\rename($tmp, $file)) {
            @\unlink($tmp);

            return null;
        }

        foreach (\glob($this->file($page, $lang, '*')) ?: [] as $stale) {
            if ($stale !== $file) {
                @\unlink($stale);
            }
        }

        return $file;
    }

    /**
     * @throws \InvalidArgumentException for page names other than lowercase letters
     */
    private function file(string $page, string $lang, string $version): string
    {
        if (!\preg_match('/^[a-z]+$/', $page)) {
            throw new \InvalidArgumentException(\sprintf('Invalid page name "%s"', $page));
        }

        return $this->stateDir . \DIRECTORY_SEPARATOR . self::PREFIX . $page . '_' . $this->instanceId . '_' . $lang . '_' . $version . '.html';
    }
}
