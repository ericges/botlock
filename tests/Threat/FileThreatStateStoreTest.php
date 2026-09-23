<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Threat;

use GES\Botlock\Threat\FileThreatStateStore;
use PHPUnit\Framework\TestCase;

final class FileThreatStateStoreTest extends TestCase
{
    private string $dir;
    private FileThreatStateStore $store;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/botlock-test-' . \bin2hex(\random_bytes(4));
        $this->store = new FileThreatStateStore($this->dir, 'inst');
    }

    protected function tearDown(): void
    {
        self::removeDir($this->dir);
    }

    public function testConstructorCreatesStateDir(): void
    {
        self::assertDirectoryExists($this->dir);
    }

    public function testUnwritableStateDirIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        new FileThreatStateStore('/proc/definitely/not/here', 'x');
    }

    public function testReadGlobalIsEmptyBeforeFirstWrite(): void
    {
        self::assertSame([], $this->store->readGlobal());
    }

    public function testUpdateGlobalPersistsReducerResult(): void
    {
        $first = $this->store->updateGlobal(fn(array $s): array => $s + ['hits' => 1]);
        $second = $this->store->updateGlobal(fn(array $s): array => ['hits' => $s['hits'] + 1]);

        self::assertSame(['hits' => 1], $first);
        self::assertSame(['hits' => 2], $second);
        self::assertSame(['hits' => 2], $this->store->readGlobal());
        self::assertFileExists($this->dir . '/botlock_state_inst.json');
    }

    public function testUpdateGlobalReturnsNullWhenReducerThrows(): void
    {
        $this->store->updateGlobal(fn(): array => ['ok' => true]);

        $result = $this->store->updateGlobal(function (): array {
            throw new \RuntimeException('boom');
        });

        self::assertNull($result);
        self::assertSame(['ok' => true], $this->store->readGlobal(), 'a failed update must not clobber the stored state');
    }

    public function testIndividualTimestampsAreRecordedAndPruned(): void
    {
        $now = \time();
        $fp = 'ab' . \str_repeat('0', 62);

        $this->store->recordIndividual($fp, $now - 100, $now - 200);
        $this->store->recordIndividual($fp, $now - 50, $now - 200);
        self::assertSame(2, $this->store->countIndividual($fp, $now - 200));

        // recording with a narrower window drops the oldest entry from disk
        $this->store->recordIndividual($fp, $now, $now - 60);
        self::assertSame(2, $this->store->countIndividual($fp, $now - 1000));
        self::assertSame(1, $this->store->countIndividual($fp, $now - 10));

        self::assertFileExists("$this->dir/ua/ab/$fp.lst");
    }

    public function testCountIndividualForUnknownFingerprintIsZero(): void
    {
        self::assertSame(0, $this->store->countIndividual('zz' . \str_repeat('1', 62), \time() - 60));
    }

    public function testCollectGarbageRemovesStaleFilesAndEmptyShards(): void
    {
        $now = \time();
        $stale = 'zz' . \str_repeat('a', 62);
        $fresh = 'yy' . \str_repeat('b', 62);

        $this->store->recordIndividual($stale, $now - 600, $now - 700);
        $this->store->recordIndividual($fresh, $now, $now - 60);
        \touch("$this->dir/ua/zz/$stale.lst", $now - 600);

        $removed = $this->store->collectGarbage($now - 300);

        self::assertSame(1, $removed);
        self::assertFileDoesNotExist("$this->dir/ua/zz/$stale.lst");
        self::assertDirectoryDoesNotExist("$this->dir/ua/zz");
        self::assertFileExists("$this->dir/ua/yy/$fresh.lst");
    }

    public function testCollectGarbageHonoursEntryBudget(): void
    {
        $now = \time();
        for ($i = 0; $i < 5; $i++) {
            $fp = \sprintf('c%d', $i) . \str_repeat('c', 62);
            $this->store->recordIndividual($fp, $now - 600, $now - 700);
            \touch("$this->dir/ua/c$i/$fp.lst", $now - 600);
        }

        // a budget of 2 inspects at most two entries, so the sweep stops early
        $firstPass = $this->store->collectGarbage($now - 300, 2);
        self::assertLessThan(5, $firstPass);
        self::assertGreaterThan(0, \count(\glob("$this->dir/ua/*/*.lst") ?: []));

        // an unbounded pass finishes the job
        $secondPass = $this->store->collectGarbage($now - 300);
        self::assertSame(5, $firstPass + $secondPass);
        self::assertSame([], \glob("$this->dir/ua/*/*.lst") ?: []);
    }

    public function testCollectGarbageWithoutIndividualDirIsNoop(): void
    {
        self::assertSame(0, $this->store->collectGarbage(\time()));
    }

    public function testCollectGarbageStartShardRotationReachesLateShards(): void
    {
        $now = \time();
        $stale = 'zz' . \str_repeat('d', 62);

        // Three fresh files in the first shard exhaust a budget of two before the stale shard is reached...
        for ($i = 0; $i < 3; $i++) {
            $this->store->recordIndividual('aa' . \str_pad((string) $i, 62, 'a'), $now, $now - 60);
        }
        $this->store->recordIndividual($stale, $now - 600, $now - 700);
        \touch("$this->dir/ua/zz/$stale.lst", $now - 600);

        self::assertSame(0, $this->store->collectGarbage($now - 300, 2, 0), 'starting at aa never gets to zz');
        self::assertFileExists("$this->dir/ua/zz/$stale.lst");

        // ...but a sweep starting at the next shard removes it, leaving the fresh files alone.
        self::assertSame(1, $this->store->collectGarbage($now - 300, 2, 1));
        self::assertFileDoesNotExist("$this->dir/ua/zz/$stale.lst");
        self::assertDirectoryDoesNotExist("$this->dir/ua/zz");
        self::assertCount(3, \glob("$this->dir/ua/aa/*.lst") ?: []);
    }

    public function testRandomStartShardEventuallyReachesEveryShard(): void
    {
        $now = \time();
        $stale = 'zz' . \str_repeat('e', 62);

        for ($i = 0; $i < 3; $i++) {
            $this->store->recordIndividual('aa' . \str_pad((string) $i, 62, 'b'), $now, $now - 60);
        }
        $this->store->recordIndividual($stale, $now - 600, $now - 700);
        \touch("$this->dir/ua/zz/$stale.lst", $now - 600);

        $removed = 0;
        for ($pass = 0; $pass < 60 && $removed === 0; $pass++) {
            $removed = $this->store->collectGarbage($now - 300, 2);
        }

        self::assertSame(1, $removed, 'with two shards a random start reaches zz within a few passes');
    }

    public function testStateDirectoriesAndFilesAreOwnerOnly(): void
    {
        $fp = 'ab' . \str_repeat('0', 62);

        $this->store->updateGlobal(fn(array $s): array => ['hits' => 1]);
        $this->store->recordIndividual($fp, \time(), \time() - 60);

        self::assertSame(0700, self::mode($this->dir));
        self::assertSame(0700, self::mode("$this->dir/ua"));
        self::assertSame(0700, self::mode("$this->dir/ua/ab"));
        self::assertSame(0600, self::mode("$this->dir/botlock_state_inst.json"));
        self::assertSame(0600, self::mode("$this->dir/ua/ab/$fp.lst"));
    }

    private static function mode(string $path): int
    {
        \clearstatcache(true, $path);

        return \fileperms($path) & 0777;
    }

    private static function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $entry) {
            $entry->isDir() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }

        \rmdir($dir);
    }
}
