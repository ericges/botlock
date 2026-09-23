<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Config\ProofOfWorkConfig;
use GES\Botlock\Http\Cookie;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Session;
use GES\Botlock\JWT;
use GES\Botlock\Middleware\SessionMiddleware;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class SessionMiddlewareTest extends TestCase
{
    public function testHttpsRequestGetsASecureSessionCookie(): void
    {
        [$request, $response] = $this->handle(Requests::make(url: 'https://example.test/'));

        $cookie = $this->sessionCookie($response);
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertIssuedFor('https://example.test', $cookie);
    }

    public function testPlainHttpRequestGetsANonSecureCookie(): void
    {
        [, $response] = $this->handle(Requests::make(url: 'http://example.test/'));

        self::assertFalse($this->sessionCookie($response)->isSecure());
    }

    public function testExternalSchemeAppliedUpstreamIsHonoured(): void
    {
        // WhoIsMiddleware sets the scheme from a trusted X-Forwarded-Proto before this middleware runs.
        $request = Requests::make(url: 'http://example.test/');
        $request->setScheme('https');

        [$request, $response] = $this->handle($request);

        self::assertTrue($this->sessionCookie($response)->isSecure());
        self::assertIssuedFor('https://example.test', $this->sessionCookie($response));
    }

    public function testUncommittedSessionSetsNoCookies(): void
    {
        $request = Requests::make();
        $request->context->fingerprint = 'fp';

        $response = (new SessionMiddleware($this->config()))->process($request, static fn(): Response => new Response(204));

        self::assertInstanceOf(Session::class, $request->context->session);
        self::assertSame([], $response->getCookies());
    }

    public function testMissingFingerprintIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);

        (new SessionMiddleware($this->config()))->process(Requests::make(), static fn(): Response => new Response(204));
    }

    /**
     * @return array{Request, Response}
     */
    private function handle(Request $request): array
    {
        $request->context->fingerprint = 'fp';

        $response = (new SessionMiddleware($this->config()))->process($request, static function (Request $request): Response {
            $request->context->session->set('grant', true);
            $request->context->session->commit();

            return new Response(204);
        });

        return [$request, $response];
    }

    private function sessionCookie(Response $response): Cookie
    {
        foreach ($response->getCookies() as $cookie) {
            if ($cookie instanceof Cookie && $cookie->getName() === Session::COOKIE_NAME && $cookie->getValue() !== '') {
                return $cookie;
            }
        }

        self::fail('no session cookie set');
    }

    /**
     * The JWT only validates against the origin it was issued for.
     */
    private static function assertIssuedFor(string $origin, Cookie $cookie): void
    {
        self::assertNotNull(JWT::tryGetPayload($cookie->getValue(), 'test-secret', 'fp', $origin));
        self::assertNull(JWT::tryGetPayload($cookie->getValue(), 'test-secret', 'fp', 'http://other.test'));
    }

    private function config(): ProofOfWorkConfig
    {
        return new ProofOfWorkConfig(secret: 'test-secret', expire: 300);
    }
}
