<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\FileChallengeTicketStore;
use GES\Botlock\Challenge\Interaction;
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
        foreach (\glob($this->dir . '/tickets/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir . '/tickets');
        @\rmdir($this->dir);
    }

    public function testSavedTicketIsConsumedExactlyOnce(): void
    {
        $ticket = self::ticket(sliderTarget: 120);

        self::assertTrue($this->store->save($ticket));
        self::assertEquals($ticket, $this->store->consume($ticket->id));
        self::assertNull($this->store->consume($ticket->id), 'a ticket is single-use');
        self::assertSame([], \glob($this->dir . '/tickets/*'));
    }

    public function testSaveReplacesTheTicketWithoutLeavingTempFiles(): void
    {
        $ticket = self::ticket();
        $this->store->save($ticket);
        $this->store->save($ticket->withInteracted());

        self::assertCount(1, \glob($this->dir . '/tickets/*'));
        self::assertTrue($this->store->consume($ticket->id)?->interacted);
    }

    public function testFileNameDoesNotRevealTheId(): void
    {
        $ticket = self::ticket();
        $this->store->save($ticket);

        [$file] = \glob($this->dir . '/tickets/*');

        self::assertStringNotContainsString($ticket->id, $file);
        self::assertStringStartsWith($this->dir . '/tickets/inst_', $file);
        self::assertSame(0600, \fileperms($file) & 0777);
    }

    public function testUnknownOrMalformedIdsYieldNull(): void
    {
        self::assertNull($this->store->consume(ChallengeTicket::newId()));
        self::assertNull($this->store->consume('../../etc/passwd'));
    }

    public function testCorruptFileIsConsumedButRejected(): void
    {
        $ticket = self::ticket();
        $this->store->save($ticket);
        [$file] = \glob($this->dir . '/tickets/*');
        \file_put_contents($file, '{nope');

        self::assertNull($this->store->consume($ticket->id));
        self::assertFileDoesNotExist($file);
    }

    public function testGarbageCollectionRemovesOnlyOldTickets(): void
    {
        $old = self::ticket();
        $fresh = self::ticket();
        $this->store->save($old);
        $this->store->save($fresh);

        $files = \glob($this->dir . '/tickets/*');
        foreach ($files as $file) {
            if (\str_contains($file, \hash('sha256', $old->id))) {
                \touch($file, \time() - 1000);
            }
        }

        self::assertSame(1, $this->store->collectGarbage(\time() - 500));
        self::assertNull($this->store->consume($old->id));
        self::assertNotNull($this->store->consume($fresh->id));
    }

    private static function ticket(?int $sliderTarget = null): ChallengeTicket
    {
        return new ChallengeTicket(
            id: ChallengeTicket::newId(),
            subject: 'fp',
            level: 2,
            interaction: $sliderTarget === null ? Interaction::Click : Interaction::Slider,
            issuedAt: \microtime(true),
            difficulty: 0.5,
            sliderTarget: $sliderTarget,
        );
    }
}
