<?php declare(strict_types=1);

namespace GES\Botlock\Tests;

use GES\Botlock\JWT;
use PHPUnit\Framework\TestCase;
use function GES\Botlock\base64url_encode;

final class JWTTest extends TestCase
{
    private const SECRET = 'jwt-secret';
    private const ISSUER = 'https://example.test';
    private const SUBJECT = 'fp-1';

    public function testRoundTrip(): void
    {
        $jwt = JWT::create(['sub' => self::SUBJECT, 'data' => ['grant' => true]], self::SECRET, self::ISSUER, 60);

        self::assertCount(3, \explode('.', $jwt));

        $payload = JWT::tryGetPayload($jwt, self::SECRET, self::SUBJECT, self::ISSUER);

        self::assertNotNull($payload);
        self::assertSame(self::SUBJECT, $payload['sub']);
        self::assertSame(['grant' => true], $payload['data']);
        self::assertSame(self::ISSUER, $payload['iss']);
        self::assertSame(JWT::getAudience(self::ISSUER), $payload['aud']);
        self::assertSame(32, \strlen($payload['jti']));
    }

    public function testWrongSecretIsRejected(): void
    {
        $jwt = JWT::create(['sub' => self::SUBJECT], self::SECRET, self::ISSUER);

        self::assertNull(JWT::tryGetPayload($jwt, 'other-secret', self::SUBJECT, self::ISSUER));
    }

    public function testSubjectMismatchIsRejected(): void
    {
        $jwt = JWT::create(['sub' => self::SUBJECT], self::SECRET, self::ISSUER);

        self::assertNull(JWT::tryGetPayload($jwt, self::SECRET, 'fp-2', self::ISSUER));
    }

    public function testIssuerMismatchIsRejected(): void
    {
        $jwt = JWT::create(['sub' => self::SUBJECT], self::SECRET, self::ISSUER);

        self::assertNull(JWT::tryGetPayload($jwt, self::SECRET, self::SUBJECT, 'https://evil.test'));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $jwt = JWT::create(['sub' => self::SUBJECT], self::SECRET, self::ISSUER, -10);

        self::assertNull(JWT::tryGetPayload($jwt, self::SECRET, self::SUBJECT, self::ISSUER));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $jwt = JWT::create(['sub' => self::SUBJECT, 'data' => ['grant' => false]], self::SECRET, self::ISSUER);
        [$h, $p, $s] = \explode('.', $jwt);

        $payload = \json_decode(\GES\Botlock\base64url_decode($p), true);
        $payload['data']['grant'] = true;
        $forged = $h . '.' . base64url_encode(\json_encode($payload)) . '.' . $s;

        self::assertNull(JWT::tryGetPayload($forged, self::SECRET, self::SUBJECT, self::ISSUER));
    }

    public function testOnlyHs256IsAccepted(): void
    {
        $header = base64url_encode(\json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = base64url_encode(\json_encode(['sub' => self::SUBJECT]));
        $jwt = "$header.$payload.";

        self::assertNull(JWT::tryGetPayload($jwt, self::SECRET, self::SUBJECT, self::ISSUER));
    }

    public function testGarbageIsRejected(): void
    {
        self::assertNull(JWT::tryGetPayload('not-a-jwt', self::SECRET, self::SUBJECT));
        self::assertNull(JWT::tryGetPayload('a.b.c', self::SECRET, self::SUBJECT));
        self::assertNull(JWT::tryGetPayload('', self::SECRET, self::SUBJECT));
    }
}
