<?php

declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * Storage configuration — every value is env-driven so the disk/bucket can be
 * tuned per deployment without touching code.
 *
 * Resolution order at runtime:
 *   1. projects/<name>/config/storage.php  (project override — copy this file there)
 *   2. plugins/Storage/config/storage.php  (this file — framework default)
 *
 * Read it anywhere with the storage_config() helper:
 *   storage_config('driver');          // 'local' | 's3'
 *   storage_config('local.root');      // dotted access into a section
 *   storage_config('s3.bucket');
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    | Which StoragePort adapter the plugin binds: 'local' (disk) or 's3'
    | (AWS S3 / DigitalOcean Spaces / Cloudflare R2 / MinIO). A project that
    | wires StoragePort itself in withPorts() overrides this entirely.
    */
    'driver' => strtolower((string) (env('STORAGE_DRIVER') ?: 'local')),

    /*
    |--------------------------------------------------------------------------
    | Read / Write Size Caps — shared by BOTH drivers
    |--------------------------------------------------------------------------
    | max_get_bytes  get() buffers the whole object in memory, so an unbounded
    |                file is an OOM primitive whenever its size is attacker-
    |                influenced. A configured 0/empty value falls back to the
    |                16 MiB default rather than disabling the cap — use
    |                readStream() for anything genuinely larger, never a raised
    |                cap. Applies to BOTH LocalStorageAdapter and
    |                S3StorageAdapter (a large S3 object is the same primitive
    |                over a network read).
    | max_put_bytes  store()/storeStream() bound against this many bytes so an
    |                attacker-influenced upload size cannot fill the disk (local
    |                driver) or run up storage cost (S3). 0 = unlimited, kept as
    |                the default so upgrading this plugin cannot silently start
    |                rejecting uploads an existing deployment already relies on.
    */
    'max_get_bytes' => (static function (): int {
        $v = (int) (env('STORAGE_MAX_GET_BYTES') ?: 0);
        return $v > 0 ? $v : 16 * 1024 * 1024;
    })(),
    'max_put_bytes' => (int) (env('STORAGE_MAX_PUT_BYTES') ?: 0),

    /*
    |--------------------------------------------------------------------------
    | Local Disk Driver
    |--------------------------------------------------------------------------
    | root       directory blobs are written under (required to enable). A RELATIVE
    |            STORAGE_ROOT (e.g. "userdata/storage") resolves against the active
    |            project root via Paths::project(); an absolute path is used as-is
    | url_base   public base URL prefix for temporaryUrl() (CDN/host)
    | url_secret HMAC secret used to sign + verify expiring temporary URLs
    */
    'local' => [
        'root'       => (static function (): string {
            $root = (string) (env('STORAGE_ROOT') ?: '');
            if ($root === '') {
                return '';
            }
            // Absolute paths (Unix "/…" or Windows "C:\…") are used verbatim;
            // a relative path is resolved under the active project root so
            // STORAGE_ROOT=userdata/storage Just Works per project.
            $isAbsolute = $root[0] === '/' || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $root);
            return $isAbsolute ? $root : Paths::project($root);
        })(),
        'url_base'   => (string) (env('STORAGE_URL_BASE') ?: ''),
        'url_secret' => (string) (env('STORAGE_URL_SECRET') ?: ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 / S3-Compatible Driver
    |--------------------------------------------------------------------------
    | bucket / region   the target bucket and its region (required to enable)
    | key / secret      static credentials — LEAVE EMPTY on EC2/ECS/EKS so the
    |                   AWS default provider chain (IAM roles) is used instead
    | endpoint          custom endpoint for non-AWS providers (Spaces/R2/MinIO)
    | use_path_style    true for MinIO / path-style endpoints
    | connect_timeout   seconds to wait for the TCP handshake (AWS SDK default:
    |                   none — a hung connection would block forever)
    | timeout           seconds to wait for the whole HTTP request/response
    | retries           left null (unset) so the AWS SDK's own retry/backoff
    |                   provider decides — set an int to force a flat count
    | send_acl          true (default) sends the object ACL on every write.
    |                   Set STORAGE_S3_ACL=false for providers without object
    |                   ACLs (MEGA S4): the header is dropped and access is
    |                   whatever the bucket grants
    | checksum          'when_supported' | 'when_required' for the SDK's request
    |                   checksum calculation and response validation. Unset keeps
    |                   the SDK default (when_supported); providers that reject
    |                   x-amz-checksum-* on PutObject (MEGA S4) need when_required
    */
    's3' => [
        'bucket'         => (string) (env('STORAGE_S3_BUCKET') ?: ''),
        'region'         => (string) (env('STORAGE_S3_REGION') ?: 'us-east-1'),
        'key'            => (string) (env('STORAGE_S3_KEY') ?: ''),
        'secret'         => (string) (env('STORAGE_S3_SECRET') ?: ''),
        'endpoint'       => env('STORAGE_S3_ENDPOINT') ?: null,
        'use_path_style' => filter_var(env('STORAGE_S3_PATH_STYLE') ?: 'false', FILTER_VALIDATE_BOOL),
        'connect_timeout' => (static function (): int {
            $v = (int) (env('STORAGE_S3_CONNECT_TIMEOUT') ?: 0);
            return $v > 0 ? $v : 10;
        })(),
        'timeout'        => (static function (): int {
            $v = (int) (env('STORAGE_S3_TIMEOUT') ?: 0);
            return $v > 0 ? $v : 30;
        })(),
        'retries'        => (static function (): ?int {
            $v = env('STORAGE_S3_RETRIES');
            return $v === null || $v === '' ? null : (int) $v;
        })(),
        // Parsed rather than `?: 'true'`: a false value must not fall through to
        // the default, whether env() hands it back as a string or a bool.
        'send_acl'       => (static function (): bool {
            $v = env('STORAGE_S3_ACL');
            return $v === null || $v === '' ? true : filter_var($v, FILTER_VALIDATE_BOOL);
        })(),
        'checksum'       => (static function (): ?string {
            $v = strtolower(trim((string) (env('STORAGE_S3_CHECKSUM') ?? '')));
            return $v === '' ? null : $v;
        })(),
    ],

];
