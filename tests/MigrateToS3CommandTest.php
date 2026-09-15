<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Storage;

use AlfacodeTeam\PhpIoCli\BufferIO;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\RangeReadableStorage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Storage\Infrastructure\Cli\MigrateToS3Command;
use Plugins\Storage\Infrastructure\LocalStorageAdapter;

#[CoversClass(MigrateToS3Command::class)]
final class MigrateToS3CommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psp-storage-migrate-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);

        // Written through the real local adapter, so the keys are exactly the
        // ones a database row would hold.
        $local = new LocalStorageAdapter($this->root);
        $local->store('%PDF-document', 'aaaa.pdf', 'submissions');
        $local->store('jpeg-cover', 'bbbb.jpg', 'submissions/covers');
        $local->store('png-avatar', 'cccc.png', 'avatars');
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->deleteTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }

    /** An in-memory bucket. $failKeys make storeStream() throw; $truncate stores one byte short. */
    private static function bucket(array $failKeys = [], bool $truncate = false): StoragePort&RangeReadableStorage
    {
        return new class ($failKeys, $truncate) implements StoragePort, RangeReadableStorage {
            /** @var array<string,string> */
            public array $objects = [];
            public int $uploads = 0;

            public function __construct(private readonly array $failKeys, private readonly bool $truncate) {}

            public function storeStream($resource, string $filename, string $path = '', string $visibility = 'private'): string
            {
                $key = $path === '' ? $filename : $path . '/' . $filename;
                if (\in_array($key, $this->failKeys, true)) {
                    throw new \RuntimeException('network down');
                }
                $bytes = (string) stream_get_contents($resource);
                $this->objects[$key] = $this->truncate ? substr($bytes, 0, -1) : $bytes;
                $this->uploads++;

                return $key;
            }

            public function exists(string $path): bool { return isset($this->objects[$path]); }
            public function size(string $path): int { return \strlen($this->objects[$path] ?? throw new \RuntimeException('missing')); }
            public function readRange(string $path, int $offset, ?int $length = null) { throw new \LogicException('unused'); }
            public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string { throw new \LogicException('unused'); }
            public function get(string $path): string { return $this->objects[$path]; }
            public function readStream(string $path) { throw new \LogicException('unused'); }
            public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string { return $path; }
            public function delete(string $path): bool { unset($this->objects[$path]); return true; }
        };
    }

    /** @return array{0:int,1:string} exit code, output */
    private function migrate(StoragePort $bucket, array $tokens = [], ?string $root = null): array
    {
        $command = new MigrateToS3Command(
            sourceRoot: fn(): string => $root ?? $this->root,
            target:     static fn(): StoragePort => $bucket,
        );
        $io = new BufferIO();

        return [$command->execute($tokens, $io), $io->getOutput()];
    }

    public function test_every_file_lands_under_its_existing_key(): void
    {
        $bucket = self::bucket();

        [$exit, $output] = $this->migrate($bucket);

        self::assertSame(0, $exit, $output);
        self::assertSame([
            'avatars/cccc.png'            => 'png-avatar',
            'submissions/aaaa.pdf'        => '%PDF-document',
            'submissions/covers/bbbb.jpg' => 'jpeg-cover',
        ], self::sorted($bucket->objects));
        self::assertStringContainsString('Copied 3 file(s)', $output);
    }

    public function test_local_files_are_never_removed(): void
    {
        $this->migrate(self::bucket());

        self::assertFileExists($this->root . '/submissions/aaaa.pdf');
        self::assertFileExists($this->root . '/avatars/cccc.png');
    }

    public function test_a_second_run_uploads_nothing(): void
    {
        $bucket = self::bucket();
        $this->migrate($bucket);

        [$exit, $output] = $this->migrate($bucket);

        self::assertSame(0, $exit);
        self::assertSame(3, $bucket->uploads, 'objects already in the bucket at the same size are skipped');
        self::assertStringContainsString('Already in the bucket: 3', $output);
    }

    public function test_a_different_size_in_the_bucket_is_uploaded_again(): void
    {
        $bucket = self::bucket();
        $bucket->objects['submissions/aaaa.pdf'] = 'stale';

        $this->migrate($bucket);

        self::assertSame('%PDF-document', $bucket->objects['submissions/aaaa.pdf']);
    }

    public function test_overwrite_uploads_even_when_present(): void
    {
        $bucket = self::bucket();
        $this->migrate($bucket);

        $this->migrate($bucket, ['--overwrite']);

        self::assertSame(6, $bucket->uploads);
    }

    public function test_dry_run_uploads_nothing(): void
    {
        $bucket = self::bucket();

        [$exit, $output] = $this->migrate($bucket, ['--dry']);

        self::assertSame(0, $exit);
        self::assertSame([], $bucket->objects);
        self::assertStringContainsString('would copy submissions/aaaa.pdf', $output);
        self::assertStringContainsString('Would copy 3 file(s)', $output);
    }

    public function test_prefix_limits_the_copy_to_one_subtree(): void
    {
        $bucket = self::bucket();

        $this->migrate($bucket, ['--prefix=submissions']);

        self::assertSame(['submissions/aaaa.pdf', 'submissions/covers/bbbb.jpg'], array_keys(self::sorted($bucket->objects)));
    }

    public function test_a_prefix_with_traversal_is_refused(): void
    {
        [$exit] = $this->migrate(self::bucket(), ['--prefix=../etc']);

        self::assertSame(1, $exit);
    }

    public function test_hidden_temp_files_and_symlinks_are_skipped(): void
    {
        file_put_contents($this->root . '/submissions/.0123abcd.tmp', 'in-flight');
        symlink('/etc/hosts', $this->root . '/submissions/link.txt');
        $bucket = self::bucket();

        [, $output] = $this->migrate($bucket);

        self::assertCount(3, $bucket->objects);
        self::assertStringContainsString('Skipped links/hidden files: 2', $output);
    }

    public function test_a_failed_upload_fails_the_command_and_the_rest_still_copy(): void
    {
        $bucket = self::bucket(failKeys: ['avatars/cccc.png']);

        [$exit, $output] = $this->migrate($bucket);

        self::assertSame(1, $exit);
        self::assertCount(2, $bucket->objects);
        self::assertStringContainsString('avatars/cccc.png: network down', $output);
        self::assertStringContainsString('Failed: 1', $output);
    }

    public function test_an_upload_that_does_not_verify_is_a_failure(): void
    {
        [$exit, $output] = $this->migrate(self::bucket(truncate: true));

        self::assertSame(1, $exit);
        self::assertStringContainsString('could not be verified', $output);
    }

    public function test_a_missing_root_fails_without_touching_the_bucket(): void
    {
        $bucket = self::bucket();

        [$exit, $output] = $this->migrate($bucket, [], $this->root . '/nope');

        self::assertSame(1, $exit);
        self::assertStringContainsString('does not exist', $output);
    }

    public function test_an_unconfigured_bucket_fails_cleanly(): void
    {
        $command = new MigrateToS3Command(
            sourceRoot: fn(): string => $this->root,
            target:     static fn(): StoragePort => throw new \RuntimeException('Storage: STORAGE_S3_BUCKET is not set.'),
        );
        $io = new BufferIO();

        self::assertSame(1, $command->execute([], $io));
        self::assertStringContainsString('STORAGE_S3_BUCKET is not set', $io->getOutput());
    }

    /** @param array<string,string> $objects */
    private static function sorted(array $objects): array
    {
        ksort($objects);

        return $objects;
    }
}
