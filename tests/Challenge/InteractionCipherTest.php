<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\InteractionCipher;
use GES\Botlock\Tests\Support\Reports;
use GES\Botlock\Tests\Support\Tracks;
use PHPUnit\Framework\TestCase;

final class InteractionCipherTest extends TestCase
{
    private const CID = '0123456789abcdef0123456789abcdef';

    public function testOpensAReportSealedLikeWebCrypto(): void
    {
        $key = InteractionCipher::newKey();
        $sealed = Reports::seal($key, self::CID, ['pos' => 120]);

        self::assertSame(['pos' => 120], InteractionCipher::open($key, self::CID, $sealed['iv'], $sealed['ct']));
        self::assertSame([], InteractionCipher::open($key, self::CID, ...\array_slice(Reports::seal($key, self::CID, []), 1)));
    }

    public function testOpensAFullSliderReportButNothingNestedDeeper(): void
    {
        $key = InteractionCipher::newKey();
        $report = ['pos' => 150, 'track' => Tracks::humanDrag(150)];
        $sealed = Reports::seal($key, self::CID, $report);

        self::assertEquals($report, InteractionCipher::open($key, self::CID, $sealed['iv'], $sealed['ct']));

        $deeper = Reports::seal($key, self::CID, ['track' => [[[[[1]]]]]]);
        self::assertNull(InteractionCipher::open($key, self::CID, $deeper['iv'], $deeper['ct']));
    }

    public function testRejectsAnotherKey(): void
    {
        $sealed = Reports::seal(InteractionCipher::newKey(), self::CID, ['pos' => 1]);

        self::assertNull(InteractionCipher::open(InteractionCipher::newKey(), self::CID, $sealed['iv'], $sealed['ct']));
    }

    public function testRejectsAnotherTicketId(): void
    {
        $key = InteractionCipher::newKey();
        $sealed = Reports::seal($key, self::CID, ['pos' => 1]);

        self::assertNull(InteractionCipher::open($key, \strrev(self::CID), $sealed['iv'], $sealed['ct']));
    }

    public function testRejectsATamperedCiphertext(): void
    {
        $key = InteractionCipher::newKey();
        $sealed = Reports::seal($key, self::CID, ['pos' => 1]);
        $bytes = \base64_decode($sealed['ct']);
        $bytes[0] = \chr(\ord($bytes[0]) ^ 1);

        self::assertNull(InteractionCipher::open($key, self::CID, $sealed['iv'], \base64_encode($bytes)));
    }

    public function testRejectsMalformedInput(): void
    {
        $key = InteractionCipher::newKey();
        $sealed = Reports::seal($key, self::CID, ['pos' => 1]);

        self::assertNull(InteractionCipher::open($key, self::CID, null, $sealed['ct']));
        self::assertNull(InteractionCipher::open($key, self::CID, $sealed['iv'], ['x']));
        self::assertNull(InteractionCipher::open($key, self::CID, \base64_encode('short'), $sealed['ct']));
        self::assertNull(InteractionCipher::open($key, self::CID, $sealed['iv'], '!!not base64!!'));
        self::assertNull(InteractionCipher::open($key, self::CID, $sealed['iv'], \base64_encode('tiny')));
        self::assertNull(InteractionCipher::open('short key', self::CID, $sealed['iv'], $sealed['ct']));
    }

    public function testRejectsAnOversizedReport(): void
    {
        $key = InteractionCipher::newKey();
        $sealed = Reports::seal($key, self::CID, ['pad' => \str_repeat('a', 70000)]);

        self::assertNull(InteractionCipher::open($key, self::CID, $sealed['iv'], $sealed['ct']));
    }

    public function testRejectsAPlaintextThatIsNoObject(): void
    {
        $key = InteractionCipher::newKey();
        $iv = \random_bytes(12);
        $ct = \openssl_encrypt('42', 'aes-256-gcm', $key, \OPENSSL_RAW_DATA, $iv, $tag, self::CID);

        self::assertNull(InteractionCipher::open($key, self::CID, \base64_encode($iv), \base64_encode($ct . $tag)));
    }
}
