<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Storage;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\RangeReadableStorage;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Storage\Infrastructure\S3StorageAdapter;
use Psr\Http\Message\RequestInterface;

/**
 * What reaches the wire for providers that are S3-compatible but not S3 — the
 * ACL and checksum switches — plus the range reads.
 *
 * The SDK's HTTP handler is replaced, so every middleware (the ACL strip, the
 * checksum calculation, signing) still runs and the assertions are made on the
 * serialised request that would have been sent. Nothing leaves the process.
 */
#[CoversClass(S3StorageAdapter::class)]
final class S3CompatibleProviderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    /** @param (callable(CommandInterface, RequestInterface): mixed)|null $respond */
    private function client(bool $sendAcl = true, ?string $checksumMode = null, ?callable $respond = null): S3Client
    {
        $client = S3StorageAdapter::buildClient(
            'eu-central-1',
            'KEY',
            'SECRET',
            'https://s3.eu-central-1.s4.mega.io',
            true,
            sendAcl: $sendAcl,
            checksumMode: $checksumMode,
        );

        $client->getHandlerList()->setHandler(function (CommandInterface $command, RequestInterface $request) use ($respond) {
            $this->sent[] = $request;
            $result = $respond === null ? new Result([]) : $respond($command, $request);

            return $result instanceof \Throwable ? Create::rejectionFor($result) : Create::promiseFor($result);
        });

        return $client;
    }

    private function lastRequest(): RequestInterface
    {
        self::assertNotEmpty($this->sent, 'no request reached the handler');

        return $this->sent[\count($this->sent) - 1];
    }

    /** @return list<string> lower-cased names of x-amz-checksum-* headers on the request */
    private static function checksumHeaders(RequestInterface $request): array
    {
        return array_values(array_filter(
            array_map('strtolower', array_keys($request->getHeaders())),
            static fn(string $name): bool => str_starts_with($name, 'x-amz-checksum-'),
        ));
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_the_acl_is_sent_by_default(): void
    {
        (new S3StorageAdapter($this->client(), 'files'))->store('hello', 'a.txt', 'docs');

        self::assertSame('private', $this->lastRequest()->getHeaderLine('x-amz-acl'));
    }

    public function test_public_visibility_still_maps_to_public_read_by_default(): void
    {
        (new S3StorageAdapter($this->client(), 'files'))->store('hello', 'a.txt', 'docs', 'public');

        self::assertSame('public-read', $this->lastRequest()->getHeaderLine('x-amz-acl'));
    }

    public function test_the_acl_is_omitted_on_a_put_when_disabled(): void
    {
        $adapter = new S3StorageAdapter($this->client(sendAcl: false), 'files');

        self::assertSame('docs/a.txt', $adapter->store('hello', 'a.txt', 'docs', 'public'));
        self::assertFalse($this->lastRequest()->hasHeader('x-amz-acl'));
    }

    public function test_the_acl_is_omitted_on_a_stream_upload_when_disabled(): void
    {
        $adapter = new S3StorageAdapter($this->client(sendAcl: false), 'files');
        $stream  = fopen('php://memory', 'r+b');
        fwrite($stream, 'streamed');
        rewind($stream);

        $adapter->storeStream($stream, 'b.bin', 'docs');
        fclose($stream);

        self::assertFalse($this->lastRequest()->hasHeader('x-amz-acl'));
    }

    public function test_the_acl_is_omitted_when_starting_a_multipart_upload(): void
    {
        // A large storeStream() goes multipart; CreateMultipartUpload is the
        // other command S4 lists x-amz-acl as unsupported on. The strip is a
        // client middleware, so it applies to the command whoever issues it.
        $client = $this->client(sendAcl: false, respond: static fn() => new Result(['UploadId' => 'u-1']));

        $client->execute($client->getCommand('CreateMultipartUpload', [
            'Bucket' => 'files',
            'Key'    => 'big.bin',
            'ACL'    => 'private',
        ]));

        self::assertFalse($this->lastRequest()->hasHeader('x-amz-acl'));
    }

    // ── Checksums ────────────────────────────────────────────────────────────

    public function test_the_sdk_default_adds_a_checksum_header_to_a_put(): void
    {
        // Pins the SDK behaviour the switch exists for. If a future SDK stops
        // doing this, the switch becomes unnecessary — not wrong.
        (new S3StorageAdapter($this->client(), 'files'))->store('hello', 'a.txt');

        self::assertNotSame([], self::checksumHeaders($this->lastRequest()));
    }

    public function test_when_required_sends_no_checksum_header_on_a_put(): void
    {
        (new S3StorageAdapter($this->client(checksumMode: 'when_required'), 'files'))->store('hello', 'a.txt');

        self::assertSame([], self::checksumHeaders($this->lastRequest()));
    }

    public function test_an_unknown_checksum_mode_is_rejected_at_build_time(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        S3StorageAdapter::buildClient('eu-central-1', 'KEY', 'SECRET', checksumMode: 'sometimes');
    }

    // ── Range reads ──────────────────────────────────────────────────────────

    public function test_it_is_range_readable(): void
    {
        self::assertInstanceOf(RangeReadableStorage::class, new S3StorageAdapter($this->client(), 'files'));
    }

    public function test_read_range_sends_an_inclusive_range_and_returns_the_body(): void
    {
        $client  = $this->client(respond: static fn() => new Result(['Body' => Utils::streamFor('cde')]));
        $adapter = new S3StorageAdapter($client, 'files');

        $stream = $adapter->readRange('docs/a.txt', 2, 3);

        self::assertSame('bytes=2-4', $this->lastRequest()->getHeaderLine('Range'));
        self::assertStringEndsWith('/files/docs/a.txt', $this->lastRequest()->getUri()->getPath());
        self::assertSame('cde', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_read_range_without_a_length_is_open_ended(): void
    {
        $client  = $this->client(respond: static fn() => new Result(['Body' => Utils::streamFor('fgh')]));
        $adapter = new S3StorageAdapter($client, 'files');

        fclose($adapter->readRange('docs/a.txt', 5));

        self::assertSame('bytes=5-', $this->lastRequest()->getHeaderLine('Range'));
    }

    public function test_read_range_rejects_a_negative_offset_without_a_request(): void
    {
        $adapter = new S3StorageAdapter($this->client(), 'files');

        try {
            $adapter->readRange('docs/a.txt', -1, 10);
            self::fail('a negative offset must be rejected');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->sent);
        }
    }

    public function test_read_range_rejects_a_zero_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new S3StorageAdapter($this->client(), 'files'))->readRange('docs/a.txt', 0, 0);
    }

    public function test_a_failed_range_read_is_translated(): void
    {
        $client = $this->client(respond: static fn(CommandInterface $command) => new S3Exception('NoSuchKey', $command));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage(S3): unable to read [docs/missing.txt] bytes=0-9');

        (new S3StorageAdapter($client, 'files'))->readRange('docs/missing.txt', 0, 10);
    }
}
