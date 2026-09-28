<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Template;

use GES\Botlock\I18n\TranslationLoader;
use GES\Botlock\Template\TemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../templates/challenge.php';
    private const BLOCKED_TEMPLATE = __DIR__ . '/../../templates/blocked.php';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @\unlink($file);
        }
    }

    public function testEscapeHelper(): void
    {
        self::assertSame(
            '&lt;a href=&quot;x&quot;&gt;&amp;&apos;',
            TemplateRenderer::escape('<a href="x">&\''),
        );
    }

    public function testRendersChallengeTemplateAndEscapes(): void
    {
        $trans = \array_fill_keys(TranslationLoader::KEYS, 'text');
        $trans['pageTitle'] = '</title><script>x</script>';

        $html = (new TemplateRenderer())->render(self::TEMPLATE, [
            'lang' => 'de',
            'trans' => $trans,
            'transJson' => '{}',
        ]);

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html lang="de">', $html);
        self::assertStringContainsString('<title>&lt;/title&gt;&lt;script&gt;x&lt;/script&gt;</title>', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('<script>window.trans = {};</script>', $html);
    }

    public function testRendersBlockedTemplateAndEscapes(): void
    {
        $trans = \array_fill_keys(TranslationLoader::KEYS, 'text');
        $trans['blockedHeading'] = '</title><script>x</script>';
        $trans['blockedRetry'] = 'Retry "{time}" <now>';

        $html = (new TemplateRenderer())->render(self::BLOCKED_TEMPLATE, [
            'lang' => 'de',
            'trans' => $trans,
        ]);

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html lang="de">', $html);
        self::assertStringContainsString('<title>&lt;/title&gt;&lt;script&gt;x&lt;/script&gt;</title>', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('<body data-botlock-blocked>', $html);
        self::assertStringContainsString('data-retry-template="Retry &quot;{time}&quot; &lt;now&gt;"', $html);
        self::assertStringContainsString('#security {', $html, 'shared styles');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function templates(): iterable
    {
        yield 'challenge' => [self::TEMPLATE];
        yield 'blocked' => [self::BLOCKED_TEMPLATE];
    }

    #[DataProvider('templates')]
    public function testTemplateWithoutVariablesRendersNothing(string $template): void
    {
        $level = \ob_get_level();
        \ob_start();

        try
        {
            include $template;
            $output = (string) \ob_get_clean();
        }
        finally
        {
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }
        }

        self::assertSame('', $output);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new TemplateRenderer())->render(__DIR__ . '/does-not-exist.php', []);
    }

    public function testOutputBufferIsRestoredWhenTemplateThrows(): void
    {
        $file = $this->tempFile("<?php echo 'partial'; throw new \\RuntimeException('boom');");
        $level = \ob_get_level();

        try
        {
            (new TemplateRenderer())->render($file, []);
            self::fail('Expected exception');
        }
        catch (\RuntimeException $e)
        {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame($level, \ob_get_level());
    }

    public function testTemplateScopeIsIsolated(): void
    {
        $file = $this->tempFile('<?= isset($this) ? "this" : "no-this" ?>|<?= $__vars["x"] ?? "hidden" ?>|<?= $x ?>');

        self::assertSame('no-this|hidden|1', (new TemplateRenderer())->render($file, ['x' => '1']));
    }

    private function tempFile(string $content): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'botlock-tpl');
        \file_put_contents($file, $content);
        $this->tempFiles[] = $file;

        return $file;
    }
}
