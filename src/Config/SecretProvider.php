<?php declare(strict_types=1);

namespace GES\Botlock\Config;

use GES\Botlock\Filesystem\PrivateDirectory;

use function GES\Botlock\randStr;

/**
 * Resolves the signing secret: BOTLOCK_SECRET when set, otherwise a secret
 * persisted in the state directory that is generated on first use.
 *
 * The generated file is created atomically with owner-only permissions, so
 * concurrent first requests all end up with the same secret and other
 * local users cannot read it.
 */
final readonly class SecretProvider
{
    public const SECRET_LENGTH = 32;

    public function __construct(
        private string $stateDir,
        private string $instanceId,
    ) {}

    /**
     * @throws \RuntimeException when no secret is configured and none can be read or generated
     */
    public function get(): string
    {
        if ($secret = Env::get('SECRET')) {
            return (string) $secret;
        }

        PrivateDirectory::ensure($this->stateDir);

        $secretFile = $this->getSecretFile();

        if (($existing = $this->readExisting($secretFile)) !== null) {
            return $existing;
        }

        $secret = randStr(self::SECRET_LENGTH);
        $this->writePrivately($secretFile, $secret);

        // link() refuses to overwrite: when another worker won the race its
        // file is complete and already 0600, so read that one instead.
        if (($existing = $this->readExisting($secretFile)) === null) {
            throw new \RuntimeException('Could not create secret file "' . $secretFile . '"');
        }

        return $existing;
    }

    public function getSecretFile(): string
    {
        return $this->stateDir . \DIRECTORY_SEPARATOR . 'botlock_secret_' . $this->instanceId;
    }

    /**
     * @throws \RuntimeException when the file exists but is unreadable or empty
     */
    private function readExisting(string $secretFile): ?string
    {
        if (!@\is_file($secretFile)) {
            return null;
        }

        // Earlier releases wrote the secret with the default umask.
        PrivateDirectory::restrictFile($secretFile);

        if (($contents = @\file_get_contents($secretFile)) === false) {
            throw new \RuntimeException('Could not read contents of "' . $secretFile . '"');
        }

        if (($secret = \trim($contents)) === '') {
            throw new \RuntimeException('Secret file "' . $secretFile . '" is empty');
        }

        return $secret;
    }

    /**
     * Writes $secret to an exclusively created, owner-only temporary file and
     * links it to $secretFile, so the final name only ever appears complete.
     * Loses silently when $secretFile already exists.
     *
     * @throws \RuntimeException when the temporary file cannot be written
     */
    private function writePrivately(string $secretFile, string $secret): void
    {
        $tmp = $secretFile . '.' . \bin2hex(\random_bytes(8)) . '.tmp';

        if (!$handle = @\fopen($tmp, 'x')) {
            throw new \RuntimeException('Could not create temporary secret file in "' . $this->stateDir . '"');
        }

        try
        {
            if (!@\chmod($tmp, PrivateDirectory::FILE_MODE)
                || \fwrite($handle, $secret) !== \strlen($secret)
                || !\fflush($handle)
                || !\fsync($handle)
            ) {
                throw new \RuntimeException('Could not write secret to file "' . $tmp . '"');
            }
        }
        catch (\Throwable $th)
        {
            \fclose($handle);
            @\unlink($tmp);
            throw $th;
        }

        \fclose($handle);

        @\link($tmp, $secretFile);
        @\unlink($tmp);
    }
}
