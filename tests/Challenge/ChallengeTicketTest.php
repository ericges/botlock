<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\ChallengeTicket;
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
}
