<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\HtmlFileResponse;
use GES\Botlock\Http\Response\HtmlResponse;
use GES\Botlock\Http\Session;
use GES\Botlock\I18n\LanguageNegotiator;
use GES\Botlock\I18n\TranslationLoader;
use GES\Botlock\Middleware\ChallengeDocumentMiddleware;
use GES\Botlock\Template\RenderedPageCache;
use GES\Botlock\Template\TemplateRenderer;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ChallengeDocumentMiddlewareTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private string $cacheDir;
    private TranslationLoader $translations;

    protected function setUp(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/botlock-mw-' . \bin2hex(\random_bytes(4));
        $this->translations = new TranslationLoader(self::ROOT . '/translations');
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->cacheDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->cacheDir);
    }

    public function testGermanClientGetsGermanPage(): void
    {
        $request = $this->request('de-DE,de;q=0.9,en;q=0.8');

        $response = $this->middleware()->process($request, $this->failingNext());
        $body = (string) $response->getBody();

        self::assertInstanceOf(HtmlFileResponse::class, $response);
        self::assertSame(401, $response->getStatus());
        self::assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame('de', $response->getHeader('Content-Language'));
        self::assertSame('Accept-Language', $response->getHeader('Vary'));
        self::assertSame('no-store', $response->getHeader('Cache-Control'));
        self::assertStringContainsString('<html lang="de">', $body);
        self::assertStringContainsString('<title>Sicherheitsüberprüfung</title>', $body);
        self::assertStringContainsString('JavaScript wird für die Sicherheitsüberprüfung benötigt.', $body);
        self::assertTrue($request->context->session->isCommited());
    }

    public function testMissingHeaderFallsBackToEnglish(): void
    {
        $response = $this->middleware()->process($this->request(null), $this->failingNext());

        self::assertSame('en', $response->getHeader('Content-Language'));
        self::assertStringContainsString('<html lang="en">', (string) $response->getBody());
        self::assertStringContainsString('<title>Security Check</title>', (string) $response->getBody());
    }

    public function testNorwegianBokmalIsAliased(): void
    {
        $response = $this->middleware()->process($this->request('nb-NO,nb;q=0.9'), $this->failingNext());

        self::assertSame('no', $response->getHeader('Content-Language'));
        self::assertStringContainsString('<html lang="no">', (string) $response->getBody());
    }

    public function testSecondRequestIsServedFromCache(): void
    {
        $middleware = $this->middleware();

        $first = $middleware->process($this->request('de'), $this->failingNext());
        $files = \glob($this->cacheDir . '/botlock_challenge_*') ?: [];
        self::assertCount(1, $files);

        // Tamper with the cached file: a re-render would overwrite the marker.
        \file_put_contents($files[0], '<!-- cached -->', \FILE_APPEND);
        $second = $middleware->process($this->request('de'), $this->failingNext());

        self::assertInstanceOf(HtmlFileResponse::class, $first);
        self::assertInstanceOf(HtmlFileResponse::class, $second);
        self::assertStringEndsWith('<!-- cached -->', (string) $second->getBody());
        self::assertCount(1, \glob($this->cacheDir . '/botlock_challenge_*') ?: []);
    }

    public function testBootstrapJsonIsScriptSafe(): void
    {
        $body = (string) $this->middleware()->process($this->request('el'), $this->failingNext())->getBody();

        self::assertSame(1, \preg_match('/<script>window\.trans = (.*?);<\/script>/', $body, $m));
        self::assertMatchesRegularExpression('/^[^<>&\']*$/', $m[1]);
        self::assertSame($this->translations->load('el'), \json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testSingleLanguageMarkup(): void
    {
        $body = (string) $this->middleware()->process($this->request('fr'), $this->failingNext())->getBody();

        self::assertSame(1, \substr_count($body, '<noscript>'));
        self::assertStringNotContainsString('class="lang-', $body);
        self::assertStringNotContainsString('loadI18n', $body);
        self::assertStringContainsString('<h1 id="main-heading">Vérification en cours…</h1>', $body);
    }

    public function testGrantedSessionPassesThrough(): void
    {
        $request = $this->request('de');
        $request->context->session->set('grant', true);

        $response = $this->middleware()->process($request, static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
        self::assertFalse($request->context->session->isCommited());
    }

    public function testUnwritableCacheStillServesThePage(): void
    {
        $blocker = \tempnam(\sys_get_temp_dir(), 'botlock-blocker');

        try
        {
            $middleware = $this->middleware(cache: new RenderedPageCache($blocker . '/sub', 'inst'));
            $response = $middleware->process($this->request('de'), $this->failingNext());
        }
        finally
        {
            @\unlink($blocker);
        }

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertNotInstanceOf(HtmlFileResponse::class, $response);
        self::assertSame(401, $response->getStatus());
        self::assertSame('de', $response->getHeader('Content-Language'));
        self::assertStringContainsString('<html lang="de">', (string) $response->getBody());
    }

    private function middleware(?string $template = null, ?RenderedPageCache $cache = null): ChallengeDocumentMiddleware
    {
        return new ChallengeDocumentMiddleware(
            new LanguageNegotiator($this->translations->supported()),
            $this->translations,
            new TemplateRenderer(),
            $cache ?? new RenderedPageCache($this->cacheDir, 'inst'),
            $template ?? self::ROOT . '/templates/challenge.php',
        );
    }

    private function request(?string $acceptLanguage): Request
    {
        $request = Requests::make(headers: $acceptLanguage === null ? [] : ['Accept-Language' => $acceptLanguage]);
        $request->context->session = new Session(
            secret: 'test-secret',
            ttl: 300,
            sub: 'fp',
            origin: 'https://example.test',
            host: 'example.test',
            secure: true,
        );

        return $request;
    }

    private function failingNext(): callable
    {
        return static function (): Response {
            self::fail('next() must not be called for an unverified request');
        };
    }
}
