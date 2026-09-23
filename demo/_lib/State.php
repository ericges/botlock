<?php declare(strict_types=1);

namespace GES\Botlock\Demo;

use GES\Botlock\Config\SecretProvider;

/**
 * Maintenance of the demo state directory (rate-limit state, rendered
 * challenge pages and the generated signing secret).
 */
final readonly class State
{
    public function __construct(
        private string $stateDir,
        private string $instanceId,
    ) {}

    /**
     * Deletes rate-limit state and cached challenge pages, keeping the
     * secret so that existing grants stay valid.
     */
    public function clear(): void
    {
        if (!\is_dir($this->stateDir)) {
            return;
        }

        $secret = $this->secretFile();
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->stateDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry->getPathname() === $secret) {
                continue;
            }

            $entry->isDir() && !$entry->isLink() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
        }
    }

    /**
     * Deletes the generated secret; BOTLOCK creates a new one on the next
     * request, which invalidates every issued grant.
     */
    public function rotateSecret(): void
    {
        @\unlink($this->secretFile());
    }

    private function secretFile(): string
    {
        return (new SecretProvider($this->stateDir, $this->instanceId))->getSecretFile();
    }
}
