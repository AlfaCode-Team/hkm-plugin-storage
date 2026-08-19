<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Storage;

use Aws\S3\S3Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Storage\Infrastructure\S3StorageAdapter;

#[CoversClass(S3StorageAdapter::class)]
final class S3StorageAdapterTest extends TestCase
{
    /** Pull the private S3Client out of an adapter for white-box assertions. */
    private function clientOf(S3StorageAdapter $adapter): S3Client
    {
        $ref  = new \ReflectionProperty(S3StorageAdapter::class, 'client');
        return $ref->getValue($adapter);
    }

    public function test_from_config_applies_region(): void
    {
        $adapter = S3StorageAdapter::fromConfig('bucket', 'eu-west-1', 'KEY', 'SECRET');

        $this->assertSame('eu-west-1', $this->clientOf($adapter)->getRegion());
    }

    public function test_from_config_applies_custom_endpoint_for_compatible_providers(): void
    {
        $adapter = S3StorageAdapter::fromConfig(
            bucket: 'space',
            region: 'us-east-1',
            key: 'KEY',
            secret: 'SECRET',
            endpoint: 'https://nyc3.digitaloceanspaces.com',
            usePathStyle: true,
        );

        $this->assertSame(
            'https://nyc3.digitaloceanspaces.com',
            (string) $this->clientOf($adapter)->getEndpoint(),
        );
    }

    public function test_explicit_static_credentials_are_used(): void
    {
        $adapter = S3StorageAdapter::fromConfig('bucket', 'us-east-1', 'AKIA-TEST', 'shh');

        $creds = $this->clientOf($adapter)->getCredentials()->wait();
        $this->assertSame('AKIA-TEST', $creds->getAccessKeyId());
        $this->assertSame('shh', $creds->getSecretKey());
    }

    public function test_empty_key_does_not_force_static_credentials(): void
    {
        // With no static key the SDK default provider chain must own credential
        // resolution (IAM roles, env, SSO). We assert fromConfig does NOT inject
        // an empty-string credential set that would shadow that chain.
        putenv('AWS_ACCESS_KEY_ID=ENV-KEY');
        putenv('AWS_SECRET_ACCESS_KEY=ENV-SECRET');
        $_SERVER['AWS_ACCESS_KEY_ID'] = 'ENV-KEY';
        $_SERVER['AWS_SECRET_ACCESS_KEY'] = 'ENV-SECRET';

        try {
            $adapter = S3StorageAdapter::fromConfig('bucket', 'us-east-1', '', '');
            $creds   = $this->clientOf($adapter)->getCredentials()->wait();

            $this->assertSame('ENV-KEY', $creds->getAccessKeyId());
        } finally {
            putenv('AWS_ACCESS_KEY_ID');
            putenv('AWS_SECRET_ACCESS_KEY');
            unset($_SERVER['AWS_ACCESS_KEY_ID'], $_SERVER['AWS_SECRET_ACCESS_KEY']);
        }
    }

    /**
     * getConfig() exposes only the SDK's small 'config' sub-namespace (fips
     * flags etc.), NOT the 'http' request options — those live on the private
     * $defaultRequestOptions property (AwsClient::__construct: "$this->
     * defaultRequestOptions = $config['http'];"), merged into every request.
     * Read the same way the existing $client reflection in this file does.
     */
    private function defaultRequestOptionsOf(S3StorageAdapter $adapter): array
    {
        $ref = new \ReflectionProperty(\Aws\AwsClient::class, 'defaultRequestOptions');
        return $ref->getValue($this->clientOf($adapter));
    }

    public function test_default_connect_and_http_timeouts_are_applied(): void
    {
        $adapter = S3StorageAdapter::fromConfig('bucket', 'us-east-1', 'KEY', 'SECRET');

        $options = $this->defaultRequestOptionsOf($adapter);
        $this->assertSame(10.0, (float) $options['connect_timeout']);
        $this->assertSame(30.0, (float) $options['timeout']);
    }

    public function test_custom_timeouts_are_applied(): void
    {
        $adapter = S3StorageAdapter::fromConfig(
            bucket: 'bucket',
            region: 'us-east-1',
            key: 'KEY',
            secret: 'SECRET',
            connectTimeout: 3,
            timeout: 15,
        );

        $options = $this->defaultRequestOptionsOf($adapter);
        $this->assertSame(3.0, (float) $options['connect_timeout']);
        $this->assertSame(15.0, (float) $options['timeout']);
    }

    public function test_retries_are_unset_by_default_so_the_sdk_default_applies(): void
    {
        // No 'retries' key forced onto the client => the SDK's own
        // RetryConfigProvider resolves it, not a value this adapter dictates.
        $adapter = S3StorageAdapter::fromConfig('bucket', 'us-east-1', 'KEY', 'SECRET');

        $config = $this->clientOf($adapter)->getConfig();
        $this->assertArrayNotHasKey('retries', $config);
    }

    // ── store()/storeStream() maxPutBytes ──────────────────────────────────
    // Both checks throw BEFORE the adapter reaches the network, so they are
    // testable without a real S3 endpoint.

    public function test_store_rejects_content_over_the_put_limit(): void
    {
        $adapter = S3StorageAdapter::fromConfig(
            'bucket', 'us-east-1', 'KEY', 'SECRET',
            maxPutBytes: 10,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/over the \d+-byte store\(\) limit/');
        $adapter->store(str_repeat('x', 11), 'f.txt');
    }

    public function test_store_stream_rejects_a_seekable_resource_over_the_put_limit(): void
    {
        $adapter = S3StorageAdapter::fromConfig(
            'bucket', 'us-east-1', 'KEY', 'SECRET',
            maxPutBytes: 10,
        );

        $resource = fopen('php://temp', 'r+b');
        fwrite($resource, str_repeat('x', 11));
        rewind($resource);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/over the \d+-byte storeStream\(\) limit/');
            $adapter->storeStream($resource, 'f.txt');
        } finally {
            fclose($resource);
        }
    }

    public function test_store_stream_rejects_non_resource(): void
    {
        $adapter = S3StorageAdapter::fromConfig('bucket', 'us-east-1', 'KEY', 'SECRET');

        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional bad arg */
        $adapter->storeStream('not-a-resource', 'f.txt');
    }
}
