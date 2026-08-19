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

        $driver      = (string) storage_config('driver', 'local');
        $maxGetBytes = (int) storage_config('max_get_bytes', 16 * 1024 * 1024);
        $maxPutBytes = (int) storage_config('max_put_bytes', 0);

        if ($driver === 's3') {
            $bucket = (string) storage_config('s3.bucket', '');
            if ($bucket === '') {
                return; // S3 selected but not configured — leave unbound
            }
            $retries = storage_config('s3.retries');
            $container->singleton(StoragePort::class, static fn() => S3StorageAdapter::fromConfig(
                bucket:         $bucket,
                region:         (string) storage_config('s3.region', 'us-east-1'),
                key:            (string) storage_config('s3.key', ''),
                secret:         (string) storage_config('s3.secret', ''),
                endpoint:       storage_config('s3.endpoint'),
                usePathStyle:   (bool) storage_config('s3.use_path_style', false),
                maxGetBytes:    $maxGetBytes,
                maxPutBytes:    $maxPutBytes,
                connectTimeout: (int) storage_config('s3.connect_timeout', 10),
                timeout:        (int) storage_config('s3.timeout', 30),
                retries:        $retries === null ? null : (int) $retries,
            ));
            return;
        }

        $root = (string) storage_config('local.root', '');
        if ($root === '') {
            return; // local driver not configured
        }
        $container->singleton(StoragePort::class, static fn() => new LocalStorageAdapter(
            root:        $root,
            urlBase:     (string) storage_config('local.url_base', ''),
            urlSecret:   (string) storage_config('local.url_secret', ''),
            maxGetBytes: $maxGetBytes,
            maxPutBytes: $maxPutBytes,
        ));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
