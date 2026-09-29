<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\ChallengeTicketStore;
use GES\Botlock\Challenge\Interaction;
use GES\Botlock\Challenge\InteractionCipher;
use PHPUnit\Framework\TestCase;

/**
 * What every ChallengeTicketStore promises, run against each of them, so
 * callers can rely on it instead of checking again: a ticket is found only
 * by the subject it was issued to, and only once.
 */
abstract class ChallengeTicketStoreContract extends TestCase
{
    abstract protected function store(): ChallengeTicketStore;

    public function testSavedTicketIsConsumedExactlyOnce(): void
    {
        $store = $this->store();
        $ticket = self::ticket(sliderTarget: 120);

        self::assertTrue($store->save($ticket));
        self::assertEquals($ticket, $store->consume('fp', $ticket->id));
        self::assertNull($store->consume('fp', $ticket->id), 'a ticket is single-use');
    }

    public function testSaveReplacesTheTicket(): void
    {
        $store = $this->store();
        $ticket = self::ticket();
        $store->save($ticket);
        $store->save($ticket->withInteracted());

        self::assertTrue($store->consume('fp', $ticket->id)?->interacted);
        self::assertNull($store->consume('fp', $ticket->id), 'one ticket, not two');
    }

    public function testAnotherSubjectFindsNothingAndLeavesTheTicket(): void
    {
        $store = $this->store();
        $ticket = self::ticket();
        $store->save($ticket);

        self::assertNull($store->consume('other-fp', $ticket->id));
        self::assertNull($store->consume('', $ticket->id), 'nor does a request without fingerprint');
        self::assertEquals($ticket, $store->consume('fp', $ticket->id), 'the owner\'s ticket is untouched');
    }

    public function testUnknownOrMalformedIdsYieldNull(): void
    {
        $store = $this->store();

        self::assertNull($store->consume('fp', ChallengeTicket::newId(\microtime(true))));
        self::assertNull($store->consume('fp', '../../etc/passwd'));
    }

    protected static function ticket(?int $sliderTarget = null, ?float $issuedAt = null): ChallengeTicket
    {
        $issuedAt ??= \microtime(true);

        return new ChallengeTicket(
            id: ChallengeTicket::newId($issuedAt),
            subject: 'fp',
            level: 2,
            interaction: $sliderTarget === null ? Interaction::Click : Interaction::Slider,
            issuedAt: $issuedAt,
            expiresAt: (int) \floor($issuedAt) + ChallengeTicket::TTL,
            difficulty: 0.5,
            sliderTarget: $sliderTarget,
            key: InteractionCipher::newKey(),
        );
    }
}
