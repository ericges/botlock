<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Template;

use GES\Botlock\Template\RenderedPageCache;
use PHPUnit\Framework\TestCase;

final class RenderedPageCacheTest extends TestCase
{
    private string $dir;
    private RenderedPageCache $cache;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/botlock-cache-' . \bin2hex(\random_bytes(4));
        $this->cache = new RenderedPageCache($this->dir, 'inst');
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
    }

    public function testFindReturnsNullBeforeStore(): void
    {
        self::assertNull($this->cache->find('de', 'v1'));
    }

    public function testStoreThenFind(): void
    {
        $path = $this->cache->store('de', 'v1', '<html>de</html>');

        self::assertNotNull($path);
        self::assertSame($this->dir . '/botlock_challenge_inst_de_v1.html', $path);
        self::assertStringEqualsFile($path, '<html>de</html>');
        self::assertSame($path, $this->cache->find('de', 'v1'));
        self::assertSame([$path], \glob($this->dir . '/*'), 'no temp file left behind');
    }

    public function testNewVersionEvictsOldVersionOfSameLanguageOnly(): void
    {
        $oldDe = $this->cache->store('de', 'v1', 'de1');
        $en = $this->cache->store('en', 'v1', 'en1');
        $newDe = $this->cache->store('de', 'v2', 'de2');

        self::assertFileDoesNotExist((string) $oldDe);
        self::assertFileExists((string) $en);
        self::assertFileExists((string) $newDe);
        self::assertNull($this->cache->find('de', 'v1'));
        self::assertSame($newDe, $this->cache->find('de', 'v2'));
    }

    public function testVersionTracksFileChanges(): void
    {
        \mkdir($this->dir);
        $a = $this->dir . '/a.txt';
        $b = $this->dir . '/b.txt';
        \file_put_contents($a, 'aaa');
        \file_put_contents($b, 'bbb');
        \touch($a, 1_700_000_000);
        \touch($b, 1_700_000_000);
        \clearstatcache();

        $v1 = RenderedPageCache::versionOf($a, $b);

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $v1);
        self::assertSame($v1, RenderedPageCache::versionOf($a, $b), 'stable for unchanged files');

        \touch($a, 1_700_000_001);
        \clearstatcache();

        self::assertNotSame($v1, RenderedPageCache::versionOf($a, $b), 'mtime change');
        self::assertNotSame($v1, RenderedPageCache::versionOf($b, $a), 'order matters');
        self::assertNotSame($v1, RenderedPageCache::versionOf($a, $this->dir . '/missing'), 'missing file');
    }

    public function testStoreReturnsNullWhenDirectoryCannotBeCreated(): void
    {
        $blocker = \tempnam(\sys_get_temp_dir(), 'botlock-blocker');
        $cache = new RenderedPageCache($blocker . '/sub', 'inst');

        try
        {
            self::assertNull($cache->store('de', 'v1', 'html'));
        }
        finally
        {
            @\unlink($blocker);
        }
    }
}
