<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

use GES\Botlock\Filesystem\PrivateDirectory;

/**
 * One JSON file per ticket in the state directory, in a directory per issue
 * minute (tickets/<instance>_<minute>/, the minute taken from the id). A
 * ticket lives five minutes, so cleanup deletes whole minutes that ended
 * before the cutoff and never lists one that is still in use.
 *
 * Consuming first renames the file to a name of its own and only then reads
 * and unlinks it; only the caller whose rename() succeeds gets the ticket,
 * so concurrent redemptions of one ticket cannot both win, and none of them
 * can remove a ticket that another one has saved again under the same name
 * meanwhile.
 *
 * The file name hashes the subject together with the id, so a client that
 * presents another client's ticket id looks for a file that does not exist
 * and cannot consume, and thereby destroy, that ticket.
 */
final readonly class FileChallengeTicketStore implements ChallengeTicketStore
{
    private const DIR = 'tickets';

    private string $dir;

    public function __construct(string $stateDir, private string $instanceId)
    {
        $this->dir = $stateDir . \DIRECTORY_SEPARATOR . self::DIR;
    }

    public function save(ChallengeTicket $ticket): bool
    {
        try {
            PrivateDirectory::ensure($this->bucket($ticket->id));
        } catch (\RuntimeException) {
            return false;
        }

        $path = $this->file($ticket->subject, $ticket->id);
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

    public function consume(string $subject, string $id): ?ChallengeTicket
    {
        if (!ChallengeTicket::isValidId($id)) {
            return null;
        }

        $path = $this->file($subject, $id);
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

        return $ticket?->id === $id && $ticket->subject === $subject ? $ticket : null;
    }

    /**
     * {@inheritDoc}
     *
     * Empties the minute directories that ended before $issuedBefore,
     * oldest first, stray temporary files included, and removes them once
     * empty. A sweep that runs out of budget leaves the rest to the next one.
     */
    public function collectGarbage(float $issuedBefore, int $maxEntries): int
    {
        $prefix = $this->dir . \DIRECTORY_SEPARATOR . $this->instanceId . '_';
        $buckets = \glob($prefix . '*', \GLOB_ONLYDIR) ?: [];
        \sort($buckets); // fixed-width hex minutes: oldest first
        $removed = 0;

        foreach ($buckets as $bucket) {
            $minute = \substr($bucket, \strlen($prefix));

            // Another instance's id may start with this one's followed by '_'.
            if (\preg_match('/^[0-9a-f]{8}$/', $minute) !== 1 || ((int) \hexdec($minute) + 1) * 60 > $issuedBefore) {
                continue;
            }

            if ($removed >= $maxEntries) {
                break;
            }

            try {
                $entries = new \FilesystemIterator($bucket, \FilesystemIterator::SKIP_DOTS);
            } catch (\UnexpectedValueException) {
                continue; // removed by a concurrent sweep
            }

            /** @var \SplFileInfo $entry */
            foreach ($entries as $entry) {
                if ($removed >= $maxEntries) {
                    break;
                }

                if (@\unlink($entry->getPathname())) {
                    $removed++;
                }
            }

            unset($entries); // release the directory handle before rmdir()
            @\rmdir($bucket); // only succeeds when empty
        }

        return $removed;
    }

    private function bucket(string $id): string
    {
        return $this->dir . \DIRECTORY_SEPARATOR . $this->instanceId . '_' . \sprintf('%08x', ChallengeTicket::issueMinute($id));
    }

    private function file(string $subject, string $id): string
    {
        return $this->bucket($id) . \DIRECTORY_SEPARATOR . \hash('sha256', $subject . "\0" . $id) . '.json';
    }
}
