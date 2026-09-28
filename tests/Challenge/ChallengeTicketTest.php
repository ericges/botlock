<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\Interaction;
use PHPUnit\Framework\TestCase;

final class ChallengeTicketTest extends TestCase
{
    public function testIdCarriesTheIssueMinute(): void
    {
        $issuedAt = 1_800_000_059.9;
        $id = ChallengeTicket::newId($issuedAt);

        self::assertTrue(ChallengeTicket::isValidId($id));
        self::assertSame(30_000_000, ChallengeTicket::issueMinute($id));
        self::assertNotSame($id, ChallengeTicket::newId($issuedAt), 'the rest is random');
    }

    public function testRenewedGivesAFreshPhaseUpToTheLifetime(): void
    {
        $ticket = self::ticket(issuedAt: 1_800_000_000.7);
        self::assertSame(1_800_000_300, $ticket->expiresAt);

        $renewed = $ticket->renewed(1_800_000_250.2);
        self::assertSame(1_800_000_550, $renewed->expiresAt);
        self::assertSame(1_800_000_300, $ticket->expiresAt, 'the original stays as it was');

        self::assertSame(1_800_000_900, $renewed->renewed(1_800_000_800.0)->expiresAt, 'capped at the lifetime');
    }

    public function testExpiryIsTheStoredDeadline(): void
    {
        $ticket = self::ticket(issuedAt: 1_800_000_000.0)->renewed(1_800_000_200.0);

        self::assertFalse($ticket->isExpired(1_800_000_500.0));
        self::assertTrue($ticket->isExpired(1_800_000_500.5));
    }

    public function testDeadlineSurvivesTheRoundTrip(): void
    {
        $ticket = self::ticket(issuedAt: 1_800_000_000.0)->renewed(1_800_000_100.0);
        self::assertEquals($ticket, ChallengeTicket::fromArray($ticket->toArray()));

        $data = $ticket->toArray();
        unset($data['exp']);
        self::assertNull(ChallengeTicket::fromArray($data), 'a ticket without a deadline is refused');
    }

    private static function ticket(float $issuedAt): ChallengeTicket
    {
        return new ChallengeTicket(
            id: ChallengeTicket::newId($issuedAt),
            subject: 'fp',
            level: 2,
            interaction: Interaction::Click,
            issuedAt: $issuedAt,
            expiresAt: (int) \floor($issuedAt) + ChallengeTicket::TTL,
        );
    }
}
