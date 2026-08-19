<?php

declare(strict_types=1);

namespace Plugins\Storage\Infrastructure;

use Aws\S3\S3Client;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use League\Flysystem\Filesystem;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Visibility;
use League\Flysystem\FilesystemException;

/**
 * S3-compatible StoragePort adapter (AWS S3, DigitalOcean Spaces, Cloudflare R2,
 * MinIO, Scaleway, …) built on the secure, maintained league/flysystem-aws-s3-v3
 * package (0 security advisories).
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
 */
final class S3StorageAdapter implements StoragePort
{
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
     * R2, MinIO). Static credentials are passed ONLY when an explicit key is
     * supplied. When $key is empty the 'credentials' entry is omitted entirely
     * so the AWS SDK's default provider chain (IAM instance/task roles, env
     * vars, SSO, ~/.aws) takes over — the standard EC2/ECS/EKS production
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

        return new S3Client($config);
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
    ): self {
        return new self(
            self::buildClient($region, $key, $secret, $endpoint, $usePathStyle, $connectTimeout, $timeout, $retries),
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

    /**
     * Byte size of a stored object without reading it. Adapter-native extra —
     * not part of StoragePort; see LocalStorageAdapter::size() for the caveat.
     */
    public function size(string $path): int
    {
        try {
            return $this->fs->fileSize($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to stat [{$path}]: {$e->getMessage()}", previous: $e);
        }
    }

    /** @see size() — same adapter-native-extra caveat applies. */
    public function lastModified(string $path): int
    {
        try {
            return $this->fs->lastModified($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to stat [{$path}]: {$e->getMessage()}", previous: $e);
        }
    }

    /** @see size() — same adapter-native-extra caveat applies. */
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
