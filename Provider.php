<?php

declare(strict_types=1);

namespace Plugins\Storage;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use Plugins\Storage\Infrastructure\Cli\MigrateToS3Command;
use Plugins\Storage\Infrastructure\LocalStorageAdapter;
use Plugins\Storage\Infrastructure\S3StorageAdapter;

/**
 * Storage plugin — local-disk adapter for the kernel StoragePort.
 *
 * Binds StoragePort to a LocalStorageAdapter built from env, but ONLY when
 * STORAGE_ROOT is set and no other StoragePort is already bound, so projects
 * that wire S3/another disk in withPorts() are left untouched (mirrors the
 * Mail/Crypto guard pattern).
 *
 * ACTIVATION: this is an ON-DEMAND module. A module whose routes use storage
 * must declare it in module.json so it joins the request graph:
 *   { "requires": ["storage.local"] }
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'storage.local';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [StoragePort::class];
    }

    public function register(ModuleContainer $container): void
    {
        if ($container->has(StoragePort::class)) {
            return; // a project already provided StoragePort
        }
        if (!self::isConfigured()) {
            return; // the selected driver is not configured — leave unbound
        }

        $container->singleton(StoragePort::class, static fn(): StoragePort => self::fromConfig());
    }

    /**
     * Whether the selected driver has what it needs: a bucket for 's3', a root
     * for 'local' (any other driver value selects local, as it always has).
     */
    public static function isConfigured(): bool
    {
        return (string) storage_config('driver', 'local') === 's3'
            ? (string) storage_config('s3.bucket', '') !== ''
            : (string) storage_config('local.root', '') !== '';
    }

    /**
     * The StoragePort this plugin's configuration describes.
     *
     * Public for a caller with no request container to register into — a
     * project binding the port app-wide in withPorts() so CLI commands, queue
     * workers and routes that never declare storage.local all share one
     * adapter. Building it here instead of in the project keeps the
     * config-to-adapter mapping in one place.
     *
     * @throws \RuntimeException when the selected driver is not configured (see isConfigured())
     */
    public static function fromConfig(): StoragePort
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException(
                'Storage: the selected driver is not configured — set STORAGE_S3_BUCKET (s3) or STORAGE_ROOT (local).',
            );
        }

        $maxGetBytes = (int) storage_config('max_get_bytes', 16 * 1024 * 1024);
        $maxPutBytes = (int) storage_config('max_put_bytes', 0);

        if ((string) storage_config('driver', 'local') === 's3') {
            return self::s3FromConfig();
        }

        return new LocalStorageAdapter(
            root:        (string) storage_config('local.root', ''),
            urlBase:     (string) storage_config('local.url_base', ''),
            urlSecret:   (string) storage_config('local.url_secret', ''),
            maxGetBytes: $maxGetBytes,
            maxPutBytes: $maxPutBytes,
        );
    }

    /**
     * The S3 adapter from the s3 section of the config, whatever STORAGE_DRIVER
     * currently selects — storage:migrate fills the bucket BEFORE the switch.
     *
     * @throws \RuntimeException when STORAGE_S3_BUCKET is not set
     */
    public static function s3FromConfig(): S3StorageAdapter
    {
        $bucket = (string) storage_config('s3.bucket', '');
        if ($bucket === '') {
            throw new \RuntimeException('Storage: STORAGE_S3_BUCKET is not set.');
        }

        $retries  = storage_config('s3.retries');
        $checksum = storage_config('s3.checksum');

        return S3StorageAdapter::fromConfig(
            bucket:         $bucket,
            region:         (string) storage_config('s3.region', 'us-east-1'),
            key:            (string) storage_config('s3.key', ''),
            secret:         (string) storage_config('s3.secret', ''),
            endpoint:       storage_config('s3.endpoint'),
            usePathStyle:   (bool) storage_config('s3.use_path_style', false),
            maxGetBytes:    (int) storage_config('max_get_bytes', 16 * 1024 * 1024),
            maxPutBytes:    (int) storage_config('max_put_bytes', 0),
            connectTimeout: (int) storage_config('s3.connect_timeout', 10),
            timeout:        (int) storage_config('s3.timeout', 30),
            retries:        $retries === null ? null : (int) $retries,
            sendAcl:        (bool) storage_config('s3.send_acl', true),
            checksumMode:   $checksum === null ? null : (string) $checksum,
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // storage:migrate — copy the local disk into the bucket ahead of the
        // switch to STORAGE_DRIVER=s3. Deferred so only a CLI process builds it;
        // the S3 client itself is built only when the command runs.
        $cli->defer(static function (CliPipeline $cli): void {
            $cli->command(new MigrateToS3Command(
                sourceRoot: static fn(): string => (string) storage_config('local.root', ''),
                target:     static fn(): StoragePort => self::s3FromConfig(),
            ));
        });
    }
}
