<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Storage;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\RangeReadableStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Storage\Infrastructure\LocalStorageAdapter;

#[CoversClass(LocalStorageAdapter::class)]
final class LocalStorageRangeTest extends TestCase
{
    private string $root;
    private LocalStorageAdapter $adapter;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psp-storage-range-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
        $this->adapter = new LocalStorageAdapter($this->root);
        $this->adapter->store('abcdefghij', 'a.txt', 'docs');
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/docs/a.txt');
        @rmdir($this->root . '/docs');
        @rmdir($this->root);
    }

    public function test_it_is_range_readable(): void
    {
        self::assertInstanceOf(RangeReadableStorage::class, $this->adapter);
    }

    public function test_size_is_the_byte_length(): void
    {
        self::assertSame(10, $this->adapter->size('docs/a.txt'));
    }

    public function test_read_range_starts_at_the_offset(): void
    {
        $stream = $this->adapter->readRange('docs/a.txt', 2, 3);

        // The contract lets the handle run past the range; the caller bounds it.
        self::assertSame('cde', fread($stream, 3));
        fclose($stream);
    }

    public function test_read_range_from_zero_and_to_eof(): void
    {
        $stream = $this->adapter->readRange('docs/a.txt', 0);
        self::assertSame('abcdefghij', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_a_missing_object_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->adapter->readRange('docs/missing.txt', 0, 1);
    }

    public function test_a_negative_offset_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->adapter->readRange('docs/a.txt', -1, 1);
    }

    public function test_a_zero_length_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->adapter->readRange('docs/a.txt', 0, 0);
    }

    public function test_traversal_is_still_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->adapter->readRange('../outside.txt', 0, 1);
    }
}
