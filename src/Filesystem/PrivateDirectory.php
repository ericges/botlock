<?php declare(strict_types=1);

namespace GES\Botlock\Filesystem;

/**
 * Creates the state directory and keeps what lives in it (the generated
 * secret, rate-limit state, cached pages) readable by the PHP user only.
 *
 * Tightening permissions is best effort: a directory or file that cannot
 * be chmod'ed (foreign owner, restrictive mount) is used as it is.
 */
final class PrivateDirectory
{
    public const DIR_MODE = 0700;
    public const FILE_MODE = 0600;

    /**
     * Creates $path (and missing parents) if necessary and restricts it to
     * the owner. Safe to call concurrently: a directory that appears between
     * the check and the mkdir() is accepted.
     *
     * @throws \RuntimeException when $path does not exist afterwards
     */
    public static function ensure(string $path): void
    {
        if (!@\is_dir($path) && !@\mkdir($path, self::DIR_MODE, true) && !@\is_dir($path)) {
            throw new \RuntimeException("State directory '$path' does not exist and could not be created");
        }

        // mkdir() honours the umask; an existing directory may have been created more openly.
        @\chmod($path, self::DIR_MODE);
    }

    /**
     * Best-effort chmod of a file inside the state directory to owner-only.
     */
    public static function restrictFile(string $path): void
    {
        @\chmod($path, self::FILE_MODE);
    }
}
