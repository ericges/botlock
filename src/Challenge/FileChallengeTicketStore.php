<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

use GES\Botlock\Filesystem\PrivateDirectory;

/**
 * One JSON file per ticket in the state directory. Consuming first renames
 * the file to a name of its own and only then reads and unlinks it; only the
 * caller whose rename() succeeds gets the ticket, so concurrent redemptions
 * of one ticket cannot both win, and none of them can remove a ticket that
 * another one has saved again under the same name meanwhile.
 */
final readonly class FileChallengeTicketStore implements ChallengeTicketStore
{
    private const DIR = 'tickets';
    private const GC_MAX_ENTRIES = 200;

    private string $dir;

    public function __construct(string $stateDir, private string $instanceId)
    {
        $this->dir = $stateDir . \DIRECTORY_SEPARATOR . self::DIR;
    }

    public function save(ChallengeTicket $ticket): bool
    {
        try {
            PrivateDirectory::ensure($this->dir);
        } catch (\RuntimeException) {
            return false;
        }

        $path = $this->file($ticket->id);
        $tmp = $path . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        $json = \json_encode($ticket->toArray(), \JSON_THROW_ON_ERROR);

        if (@\file_put_contents($tmp, $json) !== \strlen($json)) {
            @\unlink($tmp);
            return false;
        }

        PrivateDirectory::restrictFile($tmp);

        // rename() replaces atomically: a reader sees the old or the new ticket, never half of one.
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            return false;
        }

        return true;
    }

    public function consume(string $id): ?ChallengeTicket
    {
        if (!ChallengeTicket::isValidId($id)) {
            return null;
        }

        $path = $this->file($id);
        $claimed = $path . '.' . \bin2hex(\random_bytes(4)) . '.claimed';

        if (!@\rename($path, $claimed)) {
            return null;
        }

        $json = @\file_get_contents($claimed);
        @\unlink($claimed);

        if ($json === false) {
            return null;
        }

        $ticket = ChallengeTicket::fromArray(\json_decode($json, true));

        return $ticket?->id === $id ? $ticket : null;
    }

    public function collectGarbage(float $issuedBefore): int
    {
        $removed = 0;

        foreach (\glob($this->dir . \DIRECTORY_SEPARATOR . $this->instanceId . '_*') ?: [] as $index => $path) {
            if ($index >= self::GC_MAX_ENTRIES) {
                break;
            }

            $mtime = @\filemtime($path);

            if ($mtime !== false && $mtime < $issuedBefore && @\unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function file(string $id): string
    {
        return $this->dir . \DIRECTORY_SEPARATOR . $this->instanceId . '_' . \hash('sha256', $id) . '.json';
    }
}
