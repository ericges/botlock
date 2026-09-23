<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Config;

use GES\Botlock\Config\SecretProvider;
use PHPUnit\Framework\TestCase;

final class SecretProviderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        \putenv('BOTLOCK_SECRET');
        $this->dir = \sys_get_temp_dir() . '/botlock-secret-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        \putenv('BOTLOCK_SECRET');

        if (\is_dir($this->dir)) {
            \chmod($this->dir, 0700);
            foreach (\glob($this->dir . '/*') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($this->dir);
        } elseif (\is_file($this->dir)) {
            @\unlink($this->dir);
        }
    }

    public function testGeneratesSecretInPrivateDirectoryAndFile(): void
    {
        $provider = new SecretProvider($this->dir, 'inst');

        $secret = $provider->get();

        self::assertSame(SecretProvider::SECRET_LENGTH, \strlen($secret));
        self::assertSame(0700, self::mode($this->dir));
        self::assertSame(0600, self::mode($provider->getSecretFile()));
        self::assertStringEqualsFile($provider->getSecretFile(), $secret);
        self::assertSame([$provider->getSecretFile()], \glob($this->dir . '/*'), 'no temporary file left behind');
    }

    public function testSecretIsReusedAcrossCallsAndInstances(): void
    {
        $first = (new SecretProvider($this->dir, 'inst'))->get();

        self::assertSame($first, (new SecretProvider($this->dir, 'inst'))->get());
        self::assertNotSame($first, (new SecretProvider($this->dir, 'other'))->get(), 'instances have their own secret');
    }

    public function testExistingSecretFileWins(): void
    {
        // Simulates another worker having created the file first.
        \mkdir($this->dir, 0700);
        $provider = new SecretProvider($this->dir, 'inst');
        \file_put_contents($provider->getSecretFile(), "from-the-winner\n");

        self::assertSame('from-the-winner', $provider->get());
    }

    public function testLegacySecretFileIsTightened(): void
    {
        \mkdir($this->dir, 0775);
        $provider = new SecretProvider($this->dir, 'inst');
        \file_put_contents($provider->getSecretFile(), 'legacy-secret');
        \chmod($provider->getSecretFile(), 0644);

        self::assertSame('legacy-secret', $provider->get());
        self::assertSame(0600, self::mode($provider->getSecretFile()));
        self::assertSame(0700, self::mode($this->dir));
    }

    public function testEmptySecretFileIsAnError(): void
    {
        \mkdir($this->dir, 0700);
        $provider = new SecretProvider($this->dir, 'inst');
        \file_put_contents($provider->getSecretFile(), "\n");

        $this->expectException(\RuntimeException::class);

        $provider->get();
    }

    public function testUncreatableStateDirIsAnError(): void
    {
        \file_put_contents($this->dir, 'blocker');

        $this->expectException(\RuntimeException::class);

        (new SecretProvider($this->dir . '/sub', 'inst'))->get();
    }

    public function testEnvironmentSecretTakesPrecedenceWithoutTouchingTheFilesystem(): void
    {
        \putenv('BOTLOCK_SECRET=from-env');

        self::assertSame('from-env', (new SecretProvider($this->dir, 'inst'))->get());
        self::assertDirectoryDoesNotExist($this->dir);
    }

    private static function mode(string $path): int
    {
        \clearstatcache(true, $path);

        return \fileperms($path) & 0777;
    }
}
