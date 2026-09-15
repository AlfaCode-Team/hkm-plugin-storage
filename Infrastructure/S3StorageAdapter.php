<?php

declare(strict_types=1);

namespace Plugins\Storage\Infrastructure;

use Aws\CommandInterface;
use Aws\Middleware;
use Aws\S3\S3Client;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\RangeReadableStorage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use League\Flysystem\Filesystem;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Visibility;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\StreamInterface;

/**
 * S3-compatible StoragePort adapter (AWS S3, DigitalOcean Spaces, Cloudflare R2,
 * MinIO, Scaleway, MEGA S4, …) built on the secure, maintained
 * league/flysystem-aws-s3-v3 package (0 security advisories).
 *
 * Sibling to LocalStorageAdapter — selected by STORAGE_DRIVER=s3. The rest of
 * the framework depends only on the StoragePort interface, so swapping the disk
 * for a bucket changes nothing upstream.
 *
 * temporaryUrl() delegates to the SDK's native pre-signed URL (real, verifiable
 * S3 expiry) rather than the HMAC scheme the local adapter must synthesise.
 *
 * get() bounds its read against $maxGetBytes exactly like LocalStorageAdapter —
 * league/flysystem buffers the whole object in $fs->read(), so an unbounded S3
 * object is the same OOM primitive over a network read that it is over a local
 * one. store()/storeStream() bound the write side against $maxPutBytes the same
 * way (0 = unlimited, the backward-compatible default for both).
 *
 * RANGE READS. readStream() returns the GetObject body, a network stream that
 * can neither be fstat'ed nor seeked, so a consumer honouring `Range` could only
 * ever send the whole object. size() and readRange() (RangeReadableStorage) give
 * it a HEAD and a `Range:` GET instead.
 */
final class S3StorageAdapter implements StoragePort, RangeReadableStorage
{
    /** The checksum modes the AWS SDK accepts for request calculation and response validation. */
    private const CHECKSUM_MODES = ['when_supported', 'when_required'];

    private readonly Filesystem $fs;

    /**
     * @param int $maxGetBytes 0 falls back to a 16 MiB safety cap.
     * @param int $maxPutBytes 0 = unlimited.
     */
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly int $maxGetBytes = 16 * 1024 * 1024,
        private readonly int $maxPutBytes = 0,
    ) {
        $this->fs = new Filesystem(new AwsS3V3Adapter($client, $bucket));
    }

    /**
     * Build the S3Client alone, without wrapping it in an adapter.
     *
     * Split out of fromConfig() so a caller that wants the client to OUTLIVE a
     * single request — a project binding StoragePort itself in
     * bootstrap/base.php withPorts() to get persistent connections across
     * requests, instead of this plugin's per-request on-demand binding — can
     * build and cache it without duplicating the config-to-SDK-options mapping.
     *
     * $endpoint/$usePathStyle support non-AWS, S3-compatible providers (Spaces,
     * R2, MinIO, MEGA S4). Static credentials are passed ONLY when an explicit
     * key is supplied. When $key is empty the 'credentials' entry is omitted
     * entirely so the AWS SDK's default provider chain (IAM instance/task roles,
     * env vars, SSO, ~/.aws) takes over — the standard EC2/ECS/EKS production
     * setup. Passing empty-string credentials would override and break that
     * chain.
     *
     * $connectTimeout/$timeout are seconds. Left unset, the AWS SDK applies NO
     * timeout at all (Guzzle's own default is "wait forever") unless an AWS
     * config profile happens to set one — a hung TCP connection to S3, or to a
     * misconfigured/unreachable custom $endpoint, would then block the request
     * (or a Swoole worker/coroutine) indefinitely. $retries is left unset by
     * default (null) so the SDK's own retry/backoff provider decides — that is
     * more sophisticated than a flat count and shouldn't be second-guessed
     * without an operator opting in.
     *
     * COMPATIBLE PROVIDERS THAT ARE NOT QUITE S3
     * ------------------------------------------
     * $sendAcl = false removes the `ACL` parameter from every command before it
     * is serialised. Flysystem sets it on EVERY write (`private` when nothing
     * else is asked for), and some providers do not implement object ACLs at
     * all — MEGA S4 lists `x-amz-acl` as unsupported on PutObject and
     * CreateMultipartUpload. It is a client middleware rather than an adapter
     * option because Flysystem offers no way to omit the ACL: its visibility
     * converter must return a string, and the SDK skips a parameter only when it
     * is null. With it off, `$visibility` has no effect on the object — access is
     * whatever the bucket grants, which for these providers is private.
     *
     * $checksumMode sets both `request_checksum_calculation` and
     * `response_checksum_validation`. The SDK defaults to `when_supported`,
     * which adds `x-amz-checksum-crc32` to single-part uploads; providers that
     * reject those headers need `when_required`. Null keeps the SDK default.
     */
    public static function buildClient(
        string $region,
        string $key,
        string $secret,
        ?string $endpoint = null,
        bool $usePathStyle = false,
        int $connectTimeout = 10,
        int $timeout = 30,
        ?int $retries = null,
        bool $sendAcl = true,
        ?string $checksumMode = null,
    ): S3Client {
        $config = [
            'version' => 'latest',
            'region'  => $region,
            'http'    => [
                'connect_timeout' => $connectTimeout,
                'timeout'         => $timeout,
            ],
        ];
        if ($key !== '') {
            $config['credentials'] = ['key' => $key, 'secret' => $secret];
        }
        if ($endpoint !== null && $endpoint !== '') {
            $config['endpoint']                 = $endpoint;
            $config['use_path_style_endpoint']  = $usePathStyle;
        }
        if ($retries !== null) {
            $config['retries'] = $retries;
        }
        if ($checksumMode !== null) {
            if (!\in_array($checksumMode, self::CHECKSUM_MODES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Storage(S3): checksum mode [%s] is not one of: %s.',
                    $checksumMode,
                    implode(', ', self::CHECKSUM_MODES),
                ));
            }
            $config['request_checksum_calculation'] = $checksumMode;
            $config['response_checksum_validation'] = $checksumMode;
        }

        $client = new S3Client($config);

        if (!$sendAcl) {
            $client->getHandlerList()->appendInit(
                Middleware::mapCommand(static function (CommandInterface $command): CommandInterface {
                    unset($command['ACL']);

                    return $command;
                }),
                'storage-omit-acl',
            );
        }

        return $client;
    }

    /** Build the adapter from configuration — see buildClient() for the SDK options. */
    public static function fromConfig(
        string $bucket,
        string $region,
        string $key,
        string $secret,
        ?string $endpoint = null,
        bool $usePathStyle = false,
        int $maxGetBytes = 16 * 1024 * 1024,
        int $maxPutBytes = 0,
        int $connectTimeout = 10,
        int $timeout = 30,
        ?int $retries = null,
        bool $sendAcl = true,
        ?string $checksumMode = null,
    ): self {
        return new self(
            self::buildClient(
                $region,
                $key,
                $secret,
                $endpoint,
                $usePathStyle,
                $connectTimeout,
                $timeout,
                $retries,
                $sendAcl,
                $checksumMode,
            ),
            $bucket,
            $maxGetBytes,
            $maxPutBytes,
        );
    }

    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string
    {
        if ($this->maxPutBytes > 0 && strlen($contents) > $this->maxPutBytes) {
            throw new \RuntimeException(sprintf(
                'Storage(S3): content for [%s] is %d bytes, over the %d-byte store() limit. Use storeStream().',
                $filename,
                strlen($contents),
                $this->maxPutBytes,
            ));
        }

        $key = $this->join($path, $filename);
        try {
            $this->fs->write($key, $contents, [
                'visibility' => $visibility === 'public' ? Visibility::PUBLIC : Visibility::PRIVATE,
            ]);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to store [{$key}]: {$e->getMessage()}", previous: $e);
        }
        return $key;
    }

    public function storeStream($resource, string $filename, string $path = '', string $visibility = 'private'): string
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Storage(S3): storeStream expects a readable resource.');
        }

        // Best-effort: only catches a seekable, size-known resource (a real
        // file handle). A non-seekable stream (e.g. php://input on a chunked
        // upload) has no stat to check up front — S3's own multipart upload
        // does not buffer it in PHP memory either way, so this is a quota/cost
        // guard, not the OOM guard storeStream() needs on the local driver.
        if ($this->maxPutBytes > 0) {
            $stat = @fstat($resource);
            if ($stat !== false && $stat['size'] > $this->maxPutBytes) {
                throw new \RuntimeException(sprintf(
                    'Storage(S3): stream for [%s] is %d bytes, over the %d-byte storeStream() limit.',
                    $filename,
                    $stat['size'],
                    $this->maxPutBytes,
                ));
            }
        }

        $key = $this->join($path, $filename);
        try {
            $this->fs->writeStream($key, $resource, [
                'visibility' => $visibility === 'public' ? Visibility::PUBLIC : Visibility::PRIVATE,
            ]);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to store [{$key}]: {$e->getMessage()}", previous: $e);
        }
        return $key;
    }

    public function get(string $path): string
    {
        try {
            if ($this->maxGetBytes > 0) {
                $size = $this->fs->fileSize($path);
                if ($size > $this->maxGetBytes) {
                    throw new \RuntimeException(sprintf(
                        'Storage(S3): file [%s] is %d bytes, over the %d-byte get() limit. Use readStream().',
                        $path,
                        $size,
                        $this->maxGetBytes,
                    ));
                }
            }
            return $this->fs->read($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to read [{$path}]: {$e->getMessage()}", previous: $e);
        }
    }

    /** Byte size of a stored object without reading it (a HEAD request). */
    public function size(string $path): int
    {
        try {
            return $this->fs->fileSize($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to stat [{$path}]: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Adapter-native extra — not part of StoragePort, so a caller depending on
     * the port alone cannot use it.
     */
    public function lastModified(string $path): int
    {
        try {
            return $this->fs->lastModified($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to stat [{$path}]: {$e->getMessage()}", previous: $e);
        }
    }

    /** @see lastModified() — same adapter-native-extra caveat applies. */
    public function mimeType(string $path): string
    {
        try {
            return $this->fs->mimeType($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to stat [{$path}]: {$e->getMessage()}", previous: $e);
        }
    }

    public function readStream(string $path)
    {
        try {
            return $this->fs->readStream($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to read [{$path}]: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * A `Range:` GET. The body ends exactly at the range, which the contract
     * permits but does not promise — callers still bound their own reads.
     */
    public function readRange(string $path, int $offset, ?int $length = null)
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Storage(S3): readRange offset must not be negative.');
        }
        if ($length !== null && $length < 1) {
            throw new \InvalidArgumentException('Storage(S3): readRange length must be at least 1.');
        }

        $range = 'bytes=' . $offset . '-' . ($length === null ? '' : (string) ($offset + $length - 1));

        try {
            $result = $this->client->execute($this->client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key'    => ltrim($path, '/'),
                'Range'  => $range,
                '@http'  => ['stream' => true],
            ]));
        } catch (\Throwable $e) {
            throw new \RuntimeException("Storage(S3): unable to read [{$path}] {$range}: {$e->getMessage()}", previous: $e);
        }

        $body     = $result['Body'] ?? null;
        $resource = $body instanceof StreamInterface ? $body->detach() : null;
        if (!\is_resource($resource)) {
            throw new \RuntimeException("Storage(S3): unable to read [{$path}] {$range}: the response body is not a stream.");
        }

        return $resource;
    }

    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string
    {
        $command = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => ltrim($path, '/'),
        ]);
        $request = $this->client->createPresignedRequest($command, '+' . max(1, $expiresInSeconds) . ' seconds');

        return (string) $request->getUri();
    }

    public function exists(string $path): bool
    {
        try {
            return $this->fs->fileExists($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    public function delete(string $path): bool
    {
        try {
            $this->fs->delete($path);
            return true;
        } catch (FilesystemException) {
            return false;
        }
    }

    private function join(string $path, string $filename): string
    {
        $path = trim($path, '/');
        return $path === '' ? $filename : $path . '/' . $filename;
    }
}
