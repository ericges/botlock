<?php declare(strict_types=1);

namespace GES\Botlock\Config;

use function GES\Botlock\randStr;

/**
 * Resolves the signing secret: BOTLOCK_SECRET when set, otherwise a secret
 * persisted in the state directory that is generated on first use.
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

        $dir = $this->stateDir;

        if (!@\is_dir($dir) && !@\mkdir($dir, 0775, true) && !@\is_dir($dir)) {
            throw new \RuntimeException('Fallback secret dir does not exist and could not be created');
        }

        $secretFile = $this->getSecretFile();

        if (@\file_exists($secretFile)) {
            if (!$secret = \file_get_contents($secretFile)) {
                throw new \RuntimeException('Could not read contents of "' . $secretFile . '"');
            }

            return \trim($secret);
        }

        if (!@\is_writable($dir) && !@\chmod($dir, 0755)) {
            throw new \RuntimeException('Fallback secret dir is not writable and could not be chmoded');
        }

        $secret = randStr(self::SECRET_LENGTH);

        if (!\file_put_contents($secretFile, $secret)) {
            throw new \RuntimeException('Could not write secret to file "' . $secretFile . '"');
        }

        return $secret;
    }

    public function getSecretFile(): string
    {
        return $this->stateDir . \DIRECTORY_SEPARATOR . 'botlock_secret_' . $this->instanceId;
    }
}
