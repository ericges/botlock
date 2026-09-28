<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Template;

use GES\Botlock\I18n\LanguageNegotiator;
use GES\Botlock\I18n\TranslationLoader;
use GES\Botlock\Template\LocalizedPage;
use GES\Botlock\Template\RenderedPageCache;
use GES\Botlock\Template\TemplateRenderer;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class LocalizedPageTest extends TestCase
{
    private string $dir;
    private string $template;
    private string $partial;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/botlock-page-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/cache', 0700, true);

        $this->template = $this->dir . '/page.php';
        $this->partial = $this->dir . '/partial.php';
        \file_put_contents($this->template, '<p lang="<?= $e($lang) ?>"><?php include __DIR__ . \'/partial.php\'; ?></p>');
        \file_put_contents($this->partial, 'one');
    }

    protected function tearDown(): void
    {
        foreach ([...(\glob($this->dir . '/cache/*') ?: []), $this->template, $this->partial] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir . '/cache');
        @\rmdir($this->dir);
    }

    public function testExtraHeadersAreMergedWithLanguageHeaders(): void
    {
        $response = $this->page()->respond(
            Requests::make(headers: ['Accept-Language' => 'de']),
            429,
            ['Retry-After' => '60'],
        );

        self::assertSame(429, $response->getStatus());
        self::assertSame('de', $response->getHeader('Content-Language'));
        self::assertSame('Accept-Language', $response->getHeader('Vary'));
        self::assertSame('no-store', $response->getHeader('Cache-Control'));
        self::assertSame('60', $response->getHeader('Retry-After'));
        self::assertSame('<p lang="de">one</p>', $response->getBody());
    }

    public function testEditedIncludeRendersAnew(): void
    {
        $request = Requests::make(headers: ['Accept-Language' => 'de']);
        $page = $this->page();

        self::assertSame('<p lang="de">one</p>', $page->respond($request, 200)->getBody());

        \file_put_contents($this->partial, 'two!');
        \clearstatcache();

        self::assertSame('<p lang="de">two!</p>', $page->respond($request, 200)->getBody());
        self::assertCount(1, \glob($this->dir . '/cache/botlock_test_*') ?: []);
    }

    private function page(): LocalizedPage
    {
        $translations = new TranslationLoader(__DIR__ . '/../../translations');

        return new LocalizedPage(
            new LanguageNegotiator($translations->supported()),
            $translations,
            new TemplateRenderer(),
            new RenderedPageCache($this->dir . '/cache', 'inst'),
            'test',
            $this->template,
            [$this->partial],
        );
    }
}
