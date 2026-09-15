<?php

declare(strict_types=1);

namespace Plugins\Storage\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\RangeReadableStorage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;

/**
 * storage:migrate — copy every file under the local storage root into the S3
 * bucket, under the same key.
 *
 * The keys a StoragePort hands out are paths relative to its root
 * (`submissions/<hex>.pdf`), and the S3 adapter uses the same string as the
 * object key. So a file copied to its relative path is found by every database
 * row that already references it: nothing is rewritten, and switching
 * STORAGE_DRIVER from local to s3 is the whole cut-over.
 *
 *   hkm storage:migrate --dry                 # list what would be copied
 *   hkm storage:migrate                       # copy everything
 *   hkm storage:migrate --prefix=submissions  # one sub-tree only
 *   hkm storage:migrate --from=/srv/old/storage
 *
 * SAFE TO RE-RUN. An object already in the bucket at the same byte size is
 * skipped, so an interrupted run resumes where it stopped, and running it once
 * more right before the switch picks up whatever was uploaded in between.
 * --overwrite uploads regardless.
 *
 * NEVER DELETES. Local files are left in place, so pointing STORAGE_DRIVER back
 * at local is always a working rollback. Remove the local copies yourself once
 * the bucket is serving.
 *
 * Files are streamed, never read into memory. Symbolic links and hidden files
 * are skipped: the local adapter writes in-flight uploads as hidden `.<hex>.tmp`
 * files, and a link could point outside the root.
 */
final class MigrateToS3Command extends AbstractCommand
{
    private int $ignored = 0;

    /**
     * @param \Closure(): string      $sourceRoot the local driver's root (STORAGE_ROOT, resolved)
     * @param \Closure(): StoragePort $target     the S3 adapter; built only when the command runs
     */
    public function __construct(
        private readonly \Closure $sourceRoot,
        private readonly \Closure $target,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'storage:migrate';
        $this->description = 'Copy the local storage root into the S3 bucket (same keys; local files are kept)';

        $this->addOption('from', '', 'Local root to read (default: STORAGE_ROOT)', acceptsValue: true, default: '');
        $this->addOption('prefix', '', 'Only copy keys under this path, e.g. submissions', acceptsValue: true, default: '');
        $this->addOption('dry', '', 'List what would be copied without uploading');
        $this->addOption('overwrite', '', 'Upload even when the bucket already holds the key at the same size');
    }

    protected function handle(): int
    {
        $from = (string) $this->option('from', '');
        $root = $from !== '' ? $from : ($this->sourceRoot)();
        $real = $root === '' ? false : realpath($root);
        if ($real === false || !is_dir($real)) {
            $this->error(\sprintf(
                'Local storage root [%s] does not exist. Set STORAGE_ROOT or pass --from=<dir>.',
                $root,
            ));

            return self::FAILURE;
        }

        $prefix = trim(str_replace('\\', '/', (string) $this->option('prefix', '')), '/');
        if (\in_array('..', explode('/', $prefix), true)) {
            $this->error('--prefix must not contain "..".');

            return self::FAILURE;
        }

        try {
            $target = ($this->target)();
        } catch (\Throwable $e) {
            $this->error('The S3 bucket is not usable: ' . $e->getMessage());

            return self::FAILURE;
        }

        $dry       = $this->hasOption('dry');
        $overwrite = $this->hasOption('overwrite');

        $this->info(\sprintf(
            'Copying %s%s into the bucket%s.',
            $real,
            $prefix === '' ? '' : '/' . $prefix,
            $dry ? ' (dry run — nothing is uploaded)' : '',
        ));

        $copied = $skipped = $failed = 0;
        $bytes  = 0;

        foreach ($this->files($real, $prefix) as [$key, $absolute, $size]) {
            if (!$overwrite && self::alreadyThere($target, $key, $size)) {
                $skipped++;
                continue;
            }

            if ($dry) {
                $this->muted(\sprintf('  would copy %s (%s)', $key, self::human($size)));
                $copied++;
                $bytes += $size;
                continue;
            }

            if ($this->upload($target, $key, $absolute, $size)) {
                $this->muted(\sprintf('  copied %s (%s)', $key, self::human($size)));
                $copied++;
                $bytes += $size;
            } else {
                $failed++;
            }
        }

        $this->newLine();
        $summary = \sprintf(
            '%s %d file(s), %s. Already in the bucket: %d. Failed: %d. Skipped links/hidden files: %d.',
            $dry ? 'Would copy' : 'Copied',
            $copied,
            self::human($bytes),
            $skipped,
            $failed,
            $this->ignored,
        );

        if ($failed > 0) {
            $this->error($summary);
            $this->muted('Re-run the command: files already copied are skipped.');

            return self::FAILURE;
        }

        $this->success($summary);
        if (!$dry && $copied + $skipped > 0) {
            $this->muted('Local files were kept. Set STORAGE_DRIVER=s3 when you are ready to switch.');
        }

        return self::SUCCESS;
    }

    /**
     * Every regular file under $root (or $root/$prefix), as [key, absolute path, size].
     *
     * @return \Generator<int, array{0:string,1:string,2:int}>
     */
    private function files(string $root, string $prefix): \Generator
    {
        $start = $prefix === '' ? $root : $root . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, $prefix);
        if (!is_dir($start)) {
            $this->warning("Nothing under [{$start}].");

            return;
        }

        // Symlinked directories are not descended into: no FOLLOW_SYMLINKS.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $key = str_replace(\DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), \strlen($root) + 1));

            if ($file->isLink() || !$file->isFile() || self::isHiddenKey($key)) {
                $this->ignored++;
                continue;
            }

            yield [$key, $file->getPathname(), (int) $file->getSize()];
        }
    }

    private function upload(StoragePort $target, string $key, string $absolute, int $size): bool
    {
        $handle = @fopen($absolute, 'rb');
        if ($handle === false) {
            $this->error("  cannot read {$key}");

            return false;
        }

        $slash = strrpos($key, '/');
        $dir   = $slash === false ? '' : substr($key, 0, $slash);
        $name  = $slash === false ? $key : substr($key, $slash + 1);

        try {
            $stored = $target->storeStream($handle, $name, $dir);
        } catch (\Throwable $e) {
            $this->error("  {$key}: {$e->getMessage()}");

            return false;
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }

        // Confirm the bucket holds what was sent before counting it: a key the
        // adapter rewrote, or a short upload, would otherwise be reported as done.
        if ($stored !== $key || !self::alreadyThere($target, $key, $size)) {
            $this->error("  {$key}: the upload could not be verified in the bucket");

            return false;
        }

        return true;
    }

    /** The key exists in the bucket, at the same size when the port can say. */
    private static function alreadyThere(StoragePort $target, string $key, int $size): bool
    {
        try {
            if (!$target->exists($key)) {
                return false;
            }

            return !$target instanceof RangeReadableStorage || $target->size($key) === $size;
        } catch (\Throwable) {
            return false;
        }
    }

    /** A key with any segment starting with a dot. Not isHidden(): AbstractCommand declares that, final. */
    private static function isHiddenKey(string $key): bool
    {
        foreach (explode('/', $key) as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return true;
            }
        }

        return false;
    }

    private static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i     = 0;
        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return $i === 0 ? $bytes . ' B' : \sprintf('%.1f %s', $value, $units[$i]);
    }
}
