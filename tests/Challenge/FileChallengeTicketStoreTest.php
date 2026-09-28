<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\FileChallengeTicketStore;
use GES\Botlock\Challenge\Interaction;
use GES\Botlock\Challenge\InteractionCipher;
use PHPUnit\Framework\TestCase;

final class FileChallengeTicketStoreTest extends TestCase
{
    private string $dir;
    private FileChallengeTicketStore $store;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/botlock-tickets-' . \bin2hex(\random_bytes(4));
        $this->store = new FileChallengeTicketStore($this->dir, 'inst');
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/tickets/*/*') ?: [] as $file) {
            @\unlink($file);
        }
        foreach (\glob($this->dir . '/tickets/*') ?: [] as $bucket) {
            @\rmdir($bucket);
        }
        @\rmdir($this->dir . '/tickets');
        @\rmdir($this->dir);
    }

    public function testSavedTicketIsConsumedExactlyOnce(): void
    {
        $ticket = self::ticket(sliderTarget: 120);

        self::assertTrue($this->store->save($ticket));
        self::assertEquals($ticket, $this->store->consume('fp', $ticket->id));
        self::assertNull($this->store->consume('fp', $ticket->id), 'a ticket is single-use');
        self::assertSame([], \glob($this->dir . '/tickets/*/*'));
    }

    public function testSaveReplacesTheTicketWithoutLeavingTempFiles(): void
    {
        $ticket = self::ticket();
        $this->store->save($ticket);
        $this->store->save($ticket->withInteracted());

        self::assertCount(1, \glob($this->dir . '/tickets/*/*'));
        self::assertTrue($this->store->consume('fp', $ticket->id)?->interacted);
    }

    public function testTicketIsFiledUnderItsIssueMinuteWithoutRevealingTheId(): void
    {
        $ticket = self::ticket(issuedAt: 1_800_000_000.5);
        $this->store->save($ticket);

        [$file] = \glob($this->dir . '/tickets/*/*');

        self::assertSame(\sprintf('%s/tickets/inst_%08x/%s.json', $this->dir, 30_000_000, \hash('sha256', "fp\0" . $ticket->id)), $file);
        self::assertStringNotContainsString($ticket->id, $file);
        self::assertSame(0600, \fileperms($file) & 0777);
        self::assertSame(0700, \fileperms(\dirname($file)) & 0777);
    }

    public function testAnotherSubjectFindsNothingAndLeavesTheTicket(): void
    {
        $ticket = self::ticket();
        $this->store->save($ticket);

        self::assertNull($this->store->consume('other-fp', $ticket->id));
        self::assertCount(1, \glob($this->dir . '/tickets/*/*'), 'the owner\'s ticket is untouched');
        self::assertEquals($ticket, $this->store->consume('fp', $ticket->id));
    }

    public function testRequestWithoutFingerprintFindsNothing(): void
    {
        $ticket = self::ticket();
        $this->store->save($ticket);

        self::assertNull($this->store->consume('', $ticket->id));
        self::assertNotNull($this->store->consume('fp', $ticket->id));
    }

    public function testATamperedIssueMinuteFindsNothing(): void
    {
        $ticket = self::ticket();
        $this->store->save($ticket);
        $tampered = \sprintf('%08x', ChallengeTicket::issueMinute($ticket->id) - 1) . \substr($ticket->id, 8);

        self::assertNull($this->store->consume('fp', $tampered));
        self::assertCount(1, \glob($this->dir . '/tickets/*'), 'no directory is created for the forged minute');
        self::assertNotNull($this->store->consume('fp', $ticket->id));
    }

    public function testUnknownOrMalformedIdsYieldNull(): void
    {
        self::assertNull($this->store->consume('fp', ChallengeTicket::newId(\microtime(true))));
        self::assertNull($this->store->consume('fp', '../../etc/passwd'));
    }

    public function testCorruptFileIsConsumedButRejected(): void
    {
        $ticket = self::ticket();
        $this->store->save($ticket);
        [$file] = \glob($this->dir . '/tickets/*/*');
        \file_put_contents($file, '{nope');

        self::assertNull($this->store->consume('fp', $ticket->id));
        self::assertFileDoesNotExist($file);
    }

    public function testGarbageCollectionDeletesWholeExpiredMinutesOnly(): void
    {
        $now = \microtime(true);
        $old = self::ticket(issuedAt: $now - 1000);
        $fresh = self::ticket(issuedAt: $now);
        $this->store->save($old);
        $this->store->save($fresh);

        [$oldBucket] = \glob($this->dir . \sprintf('/tickets/inst_%08x', ChallengeTicket::issueMinute($old->id)));
        \file_put_contents($oldBucket . '/leftover.json.0000.tmp', '');

        // Another instance's minute directory is not this store's business.
        \mkdir($this->dir . '/tickets/inst_other_00000001');
        \touch($this->dir . '/tickets/inst_other_00000001/x.json');

        self::assertSame(2, $this->store->collectGarbage($now - 360, 100));
        self::assertDirectoryDoesNotExist($oldBucket);
        self::assertNotNull($this->store->consume('fp', $fresh->id));
        self::assertFileExists($this->dir . '/tickets/inst_other_00000001/x.json');
    }

    public function testGarbageCollectionStopsAtItsBudgetAndResumes(): void
    {
        $issuedAt = \microtime(true) - 1000;
        for ($i = 0; $i < 5; $i++) {
            $this->store->save(self::ticket(issuedAt: $issuedAt));
        }

        $cutoff = \microtime(true) - 360;

        self::assertSame(2, $this->store->collectGarbage($cutoff, 2));
        self::assertSame(2, $this->store->collectGarbage($cutoff, 2));
        self::assertSame(1, $this->store->collectGarbage($cutoff, 2));
        self::assertSame([], \glob($this->dir . '/tickets/*'));
        self::assertSame(0, $this->store->collectGarbage($cutoff, 2));
    }

    private static function ticket(?int $sliderTarget = null, ?float $issuedAt = null): ChallengeTicket
    {
        $issuedAt ??= \microtime(true);

        return new ChallengeTicket(
            id: ChallengeTicket::newId($issuedAt),
            subject: 'fp',
            level: 2,
            interaction: $sliderTarget === null ? Interaction::Click : Interaction::Slider,
            issuedAt: $issuedAt,
            difficulty: 0.5,
            sliderTarget: $sliderTarget,
            key: InteractionCipher::newKey(),
        );
    }
}
