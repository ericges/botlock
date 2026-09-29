<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Crawler\CrawlerVerification;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\HtmlFileResponse;
use GES\Botlock\Http\Response\JsonResponse;
use GES\Botlock\I18n\LanguageNegotiator;
use GES\Botlock\I18n\TranslationLoader;
use GES\Botlock\Middleware\ThreatBlockMiddleware;
use GES\Botlock\Template\LocalizedPage;
use GES\Botlock\Template\RenderedPageCache;
use GES\Botlock\Template\TemplateRenderer;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ThreatBlockMiddlewareTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/botlock-block-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->cacheDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->cacheDir);
    }

    public function testLevelsBelowFourContinue(): void
    {
        foreach ([null, 0, 1, 2, 3] as $level) {
            $request = Requests::make();
            $request->context->threatLevel = $level;

            self::assertSame(299, $this->middleware()->process($request, self::next())->getStatus(), 'level ' . \var_export($level, true));
        }
    }

    public function testLevelFourIsRefusedWithRetryAfter(): void
    {
        $request = Requests::make(headers: ['Accept' => 'text/html']);
        $request->context->threatLevel = 4;

        $response = $this->middleware(window: 90)->process($request, self::failingNext());

        self::assertSame(429, $response->getStatus());
        self::assertSame('90', $response->getHeader('Retry-After'));
        self::assertSame('Too Many Requests', $response->getHeader('Botlock-Error'));
    }

    public function testBrowsersGetTheBlockedPageInTheirLanguage(): void
    {
        $request = Requests::make(headers: [
            'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
            'Accept-Language' => 'de-DE,de;q=0.9',
        ]);
        $request->context->threatLevel = 4;

        $response = $this->middleware(window: 90)->process($request, self::failingNext());
        $body = (string) $response->getBody();

        self::assertInstanceOf(HtmlFileResponse::class, $response);
        self::assertSame(429, $response->getStatus());
        self::assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame('de', $response->getHeader('Content-Language'));
        self::assertSame('Accept-Language', $response->getHeader('Vary'));
        self::assertSame('no-store', $response->getHeader('Cache-Control'));
        self::assertSame('90', $response->getHeader('Retry-After'));
        self::assertSame('Too Many Requests', $response->getHeader('Botlock-Error'));
        self::assertStringContainsString('<html lang="de">', $body);
        self::assertStringContainsString('<h1 id="main-heading">Zu viele Anfragen</h1>', $body);
        self::assertStringContainsString('<body data-botlock-blocked>', $body);
        self::assertStringContainsString('data-retry-template="Sie können es {time} erneut versuchen."', $body);
    }

    public function testBlockedPageCarriesTheRetryTimeOfTheHeader(): void
    {
        $request = Requests::make(headers: ['Accept' => 'text/html']);
        $request->context->threatLevel = 4;

        $response = $this->middleware(window: 90)->process($request, self::failingNext());
        $body = (string) $response->getBody();

        self::assertSame('90', $response->getHeader('Retry-After'));
        self::assertStringContainsString('data-retry-after="90"', $body);
        self::assertStringNotContainsString("method: 'HEAD'", $body, 'the page does not ask again');
        self::assertStringContainsString((string) \file_get_contents(self::ROOT . '/templates/partials/relative-time.js'), $body, 'the shared formatter, verbatim');
        self::assertStringContainsString("note.dataset.retryTemplate.replace('{time}', relativeTime(seconds))", $body);
    }

    public function testSecondBrowserRequestIsServedFromCache(): void
    {
        $middleware = $this->middleware();
        $request = static function (): Request {
            $request = Requests::make(headers: ['Accept' => 'text/html', 'Accept-Language' => 'en']);
            $request->context->threatLevel = 4;

            return $request;
        };

        $middleware->process($request(), self::failingNext());
        $files = \glob($this->cacheDir . '/botlock_blocked_*') ?: [];
        self::assertCount(1, $files);

        \file_put_contents($files[0], '<!-- cached -->', \FILE_APPEND);

        self::assertStringEndsWith('<!-- cached -->', (string) $middleware->process($request(), self::failingNext())->getBody());
    }

    public function testJsonClientsKeepTheJsonAnswer(): void
    {
        $request = Requests::make(headers: ['Accept' => 'application/json, text/html']);
        $request->context->threatLevel = 4;

        $response = $this->middleware()->process($request, self::failingNext());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(429, $response->getStatus());
        self::assertSame('60', $response->getHeader('Retry-After'));
        self::assertSame(['ok' => false, 'error' => 'Too Many Requests', 'code' => 429], \json_decode((string) $response->getBody(), true));
    }

    public function testOtherClientsKeepThePlainAnswer(): void
    {
        foreach ([[], ['Accept' => '*/*']] as $headers) {
            $request = Requests::make(headers: $headers);
            $request->context->threatLevel = 4;

            $response = $this->middleware()->process($request, self::failingNext());

            self::assertSame(429, $response->getStatus());
            self::assertSame('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
            self::assertSame('60', $response->getHeader('Retry-After'));
            self::assertSame('Too Many Requests', $response->getBody());
        }
    }

    public function testTrustedGoodBotsAreRefusedToo(): void
    {
        $request = Requests::make(headers: ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);
        $request->context->threatLevel = 4;
        $request->context->crawlerVerification = CrawlerVerification::Verified;

        self::assertSame(429, $this->middleware()->process($request, self::failingNext())->getStatus());
    }

    public function testChallengeActionsAreRefusedButStatusIsNot(): void
    {
        $challenge = Requests::make(query: ['_botlock' => 'challenge']);
        $challenge->context->threatLevel = 4;
        self::assertSame(429, $this->middleware()->process($challenge, self::failingNext())->getStatus());

        $status = Requests::make(query: ['_botlock' => 'status']);
        $status->context->threatLevel = 4;
        self::assertSame(299, $this->middleware()->process($status, self::next())->getStatus());
    }

    private function middleware(int $window = 60): ThreatBlockMiddleware
    {
        $translations = new TranslationLoader(self::ROOT . '/translations');
        $config = new RateLimitConfig(individualRateWindowSec: $window);

        return new ThreatBlockMiddleware(
            $config,
            new LocalizedPage(
                new LanguageNegotiator($translations->supported()),
                $translations,
                new TemplateRenderer(),
                new RenderedPageCache($this->cacheDir, 'inst'),
                'blocked',
                self::ROOT . '/templates/blocked.php',
                [self::ROOT . '/templates/partials/style.css', self::ROOT . '/templates/partials/relative-time.js'],
                ['retryAfter' => $config->retryAfterSec()],
            ),
        );
    }

    private static function next(): callable
    {
        return static fn (Request $request): Response => new Response(299);
    }

    private static function failingNext(): callable
    {
        return static function (): never {
            self::fail('a level-4 request must not continue');
        };
    }
}
