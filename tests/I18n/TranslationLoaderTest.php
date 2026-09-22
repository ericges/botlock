<?php declare(strict_types=1);

namespace GES\Botlock\Tests\I18n;

use GES\Botlock\I18n\TranslationLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslationLoaderTest extends TestCase
{
    private const DIR = __DIR__ . '/../../translations';

    private TranslationLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new TranslationLoader(self::DIR);
    }

    public function testEveryLanguageLoadsWithIdenticalKeySet(): void
    {
        foreach ($this->loader->supported() as $code) {
            $dict = $this->loader->load($code);

            self::assertSame(TranslationLoader::KEYS, \array_keys($dict), "key set of {$code}");

            foreach ($dict as $key => $value) {
                self::assertIsString($value, "{$code}.{$key}");
                self::assertNotSame('', \trim($value), "{$code}.{$key} is empty");
            }
        }
    }

    public function testLanguageFilesMatchAllowList(): void
    {
        $files = \array_map(
            static fn(string $path): string => \basename($path, '.php'),
            \glob(self::DIR . '/*.php') ?: [],
        );
        \sort($files, \SORT_STRING);

        self::assertCount(24, $files);
        self::assertSame(TranslationLoader::LANGUAGES, $files);
    }

    public function testFilePointsIntoTranslationDir(): void
    {
        self::assertSame(self::DIR . '/de.php', $this->loader->file('de'));
        self::assertFileExists($this->loader->file('de'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedCodes(): iterable
    {
        yield 'unknown' => ['xx'];
        yield 'uppercase' => ['EN'];
        yield 'traversal' => ['../../index'];
        yield 'null byte' => ["de\0"];
        yield 'nested traversal' => ['de/../en'];
        yield 'empty' => [''];
    }

    #[DataProvider('rejectedCodes')]
    public function testLoadRejectsUnknownCodes(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->loader->load($code);
    }

    #[DataProvider('rejectedCodes')]
    public function testFileRejectsUnknownCodes(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->loader->file($code);
    }
}
