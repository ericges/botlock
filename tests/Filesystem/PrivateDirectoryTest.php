<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Filesystem;

use GES\Botlock\Filesystem\PrivateDirectory;
use PHPUnit\Framework\TestCase;

final class PrivateDirectoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/botlock-priv-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir . '/nested/leaf', $this->dir . '/nested', $this->dir] as $path) {
            if (\is_dir($path)) {
                @\chmod($path, 0700);
                @\rmdir($path);
            } elseif (\is_file($path)) {
                @\unlink($path);
            }
        }
    }

    public function testCreatesMissingDirectoriesOwnerOnly(): void
    {
        PrivateDirectory::ensure($this->dir . '/nested/leaf');

        self::assertSame(0700, self::mode($this->dir . '/nested/leaf'));
        self::assertSame(0700, self::mode($this->dir . '/nested'), 'intermediate directories created by mkdir(recursive) are private too');
    }

    public function testDefeatsPermissiveUmask(): void
    {
        $umask = \umask(0);

        try {
            PrivateDirectory::ensure($this->dir);
        } finally {
            \umask($umask);
        }

        self::assertSame(0700, self::mode($this->dir));
    }

    public function testTightensExistingSharedDirectory(): void
    {
        \mkdir($this->dir, 0775);
        \chmod($this->dir, 0775);

        PrivateDirectory::ensure($this->dir);

        self::assertSame(0700, self::mode($this->dir));
    }

    public function testExistingPrivateDirectoryIsAccepted(): void
    {
        \mkdir($this->dir, 0700);

        PrivateDirectory::ensure($this->dir);

        self::assertDirectoryExists($this->dir);
        self::assertSame(0700, self::mode($this->dir));
    }

    public function testThrowsWhenDirectoryCannotBeCreated(): void
    {
        \file_put_contents($this->dir, 'blocker');

        $this->expectException(\RuntimeException::class);

        PrivateDirectory::ensure($this->dir . '/sub');
    }

    public function testRestrictFileIsOwnerOnlyAndSilentOnMissingFile(): void
    {
        \mkdir($this->dir, 0700);
        $file = $this->dir . '/nested';
        \file_put_contents($file, 'x');
        \chmod($file, 0644);

        PrivateDirectory::restrictFile($file);
        PrivateDirectory::restrictFile($this->dir . '/does-not-exist');

        self::assertSame(0600, self::mode($file));
    }

    private static function mode(string $path): int
    {
        \clearstatcache(true, $path);

        return \fileperms($path) & 0777;
    }
}
