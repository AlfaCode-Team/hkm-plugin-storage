<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Storage\Infrastructure\LocalStorageAdapter;

/**
 * Regression cover for ST-01 (a failed chmod left a "private" file
 * world-readable while reporting success), ST-02 (unbounded get()) and ST-03
 * (containment was textual, so a symlink escaped the root).
 */
#[CoversClass(LocalStorageAdapter::class)]
final class LocalStorageSecurityTest extends TestCase
{
    private string $root;
    private string $outside;

    protected function setUp(): void
    {
        $base          = sys_get_temp_dir() . '/hkm-storage-' . bin2hex(random_bytes(6));
        $this->root    = $base . '/root';
        $this->outside = $base . '/outside';
        mkdir($this->root, 0775, true);
        mkdir($this->outside, 0775, true);
        file_put_contents($this->outside . '/secret.txt', 'TOP SECRET');
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->outside] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                is_link($f) || is_file($f) ? @unlink($f) : @rmdir($f);
            }
            @rmdir($dir);
        }
        @rmdir(\dirname($this->root));
    }

    private function adapter(): LocalStorageAdapter
    {
        return new LocalStorageAdapter($this->root);
    }

    // ── ST-01 ───────────────────────────────────────────────────────────────

    public function test_private_files_are_written_owner_only(): void
    {
        $path = $this->adapter()->store('data', 'a.txt', '', 'private');

        self::assertSame(0600, fileperms($this->root . '/' . $path) & 0777);
    }

    public function test_public_files_are_world_readable(): void
    {
        $path = $this->adapter()->store('data', 'b.txt', '', 'public');

        self::assertSame(0644, fileperms($this->root . '/' . $path) & 0777);
    }

    // ── ST-03 ───────────────────────────────────────────────────────────────

    public function test_a_symlink_out_of_the_root_is_refused(): void
    {
        // No ".." anywhere, so the textual check passed and the read escaped.
        symlink($this->outside, $this->root . '/link');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/escapes the storage root/');

        $this->adapter()->get('link/secret.txt');
    }

    public function test_textual_traversal_is_still_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->adapter()->get('../outside/secret.txt');
    }

    public function test_a_normal_path_inside_the_root_works(): void
    {
        $path = $this->adapter()->store('hello', 'c.txt');

        self::assertSame('hello', $this->adapter()->get($path));
    }

    // ── ST-02 ───────────────────────────────────────────────────────────────

    public function test_get_refuses_a_file_over_the_limit(): void
    {
        $_ENV['STORAGE_MAX_GET_BYTES'] = '64';

        try {
            $path = $this->adapter()->store(str_repeat('x', 500), 'big.txt');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/over the \d+-byte get\(\) limit/');

            $this->adapter()->get($path);
        } finally {
            unset($_ENV['STORAGE_MAX_GET_BYTES']);
        }
    }

    public function test_readStream_is_not_subject_to_the_get_limit(): void
    {
        $_ENV['STORAGE_MAX_GET_BYTES'] = '64';

        try {
            $path   = $this->adapter()->store(str_repeat('x', 500), 'big2.txt');
            $handle = $this->adapter()->readStream($path);

            self::assertIsResource($handle);
            fclose($handle);
        } finally {
            unset($_ENV['STORAGE_MAX_GET_BYTES']);
        }
    }
}
