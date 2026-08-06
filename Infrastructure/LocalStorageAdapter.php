<?php

declare(strict_types=1);

namespace Plugins\Storage\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;

/**
 * Local-disk StoragePort adapter (GDA rewrite of the 0.3 Filesystem providers).
 *
 * Zero-dependency: native fopen/flock/rename only — no Flysystem, no Laravel
 * FilesystemManager. Writes are atomic (write to a temp file under the same
 * directory, then rename over the target) and guarded against path traversal so
 * a caller can never escape the configured root.
 *
 * temporaryUrl() produces an HMAC-signed, expiring URL of the form
 *   {base}/{path}?expires={ts}&signature={hmac}
 * which a download controller can verify with the same secret. When no secret is
 * configured the URL is returned unsigned (suitable for local/dev use only).
 */
final class LocalStorageAdapter implements StoragePort
{
    private readonly string $root;

    public function __construct(
        string $root,
        private readonly string $urlBase = '',
        private readonly string $urlSecret = '',
    ) {
        $resolved = rtrim($root, '/\\');
        $this->root = $resolved === '' ? '/' : $resolved;
    }

    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string
    {
        $relative = $this->join($path, $filename);
        $absolute = $this->absolute($relative);

        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Storage: unable to create directory [{$dir}].");
        }

        // Atomic write: temp file in the same dir, then rename over the target.
        $temp = $dir . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Storage: unable to open temp file in [{$dir}].");
        }
        try {
            flock($handle, LOCK_EX);
            $expected = strlen($contents);
            $written  = fwrite($handle, $contents);
            // Detect short writes (e.g. disk full) before publishing the file.
            if ($written === false || $written !== $expected) {
                throw new \RuntimeException("Storage: failed to write file [{$relative}] (disk full?).");
            }
            fflush($handle);
            // Durability: flush kernel buffers to disk so the rename publishes
            // a fully-persisted file even across a crash/power loss.
            if (function_exists('fsync')) {
                @fsync($handle);
            }
            flock($handle, LOCK_UN);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($temp);
            throw $e;
        }
        fclose($handle);

        if (!rename($temp, $absolute)) {
            @unlink($temp);
            throw new \RuntimeException("Storage: unable to persist file [{$relative}].");
        }

        $this->applyVisibility($absolute, $visibility, $relative);

        return $relative;
    }

    public function storeStream($resource, string $filename, string $path = '', string $visibility = 'private'): string
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Storage: storeStream expects a readable resource.');
        }

        $relative = $this->join($path, $filename);
        $absolute = $this->absolute($relative);

        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Storage: unable to create directory [{$dir}].");
        }

        // Atomic write: stream-copy to a temp file in the same dir, then rename.
        $temp   = $dir . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Storage: unable to open temp file in [{$dir}].");
        }
        try {
            flock($handle, LOCK_EX);
            if (stream_copy_to_stream($resource, $handle) === false) {
                throw new \RuntimeException("Storage: failed to stream file [{$relative}] (disk full?).");
            }
            fflush($handle);
            if (function_exists('fsync')) {
                @fsync($handle);
            }
            flock($handle, LOCK_UN);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($temp);
            throw $e;
        }
        fclose($handle);

        if (!rename($temp, $absolute)) {
            @unlink($temp);
            throw new \RuntimeException("Storage: unable to persist file [{$relative}].");
        }

        $this->applyVisibility($absolute, $visibility, $relative);

        return $relative;
    }

    /**
     * Read a whole object into memory.
     *
     * SMALL FILES ONLY. This buffers the entire object, so a large upload can
     * exhaust the process memory limit — and since uploads are usually
     * attacker-influenced in size, that is a denial-of-service primitive. Use
     * readStream() for anything that is not known to be small.
     *
     * The cap (STORAGE_MAX_GET_BYTES, default 16 MiB) turns that failure into a
     * clear exception instead of an OOM kill that takes the worker with it.
     */
    public function get(string $path): string
    {
        $absolute = $this->absolute($path);
        if (!is_file($absolute)) {
            throw new \RuntimeException("Storage: file [{$path}] not found.");
        }

        $limit = (int) (\function_exists('env') ? (env('STORAGE_MAX_GET_BYTES') ?: 0) : 0)
            ?: 16 * 1024 * 1024;
        $size  = @filesize($absolute);

        if ($size !== false && $size > $limit) {
            throw new \RuntimeException(sprintf(
                'Storage: file [%s] is %d bytes, over the %d-byte get() limit. Use readStream().',
                $path,
                $size,
                $limit,
            ));
        }

        $contents = file_get_contents($absolute);
        if ($contents === false) {
            throw new \RuntimeException("Storage: unable to read file [{$path}].");
        }
        return $contents;
    }

    public function readStream(string $path)
    {
        $absolute = $this->absolute($path);
        if (!is_file($absolute)) {
            throw new \RuntimeException("Storage: file [{$path}] not found.");
        }
        $handle = fopen($absolute, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Storage: unable to open file [{$path}].");
        }
        return $handle;
    }

    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string
    {
        $relative = $this->normalise($path);
        $base     = rtrim($this->urlBase, '/');
        $url      = ($base === '' ? '' : $base) . '/' . ltrim($relative, '/');

        if ($this->urlSecret === '') {
            return $url;
        }

        $expires   = time() + max(1, $expiresInSeconds);
        $signature = hash_hmac('sha256', $relative . '|' . $expires, $this->urlSecret);

        return $url . '?expires=' . $expires . '&signature=' . $signature;
    }

    public function exists(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function delete(string $path): bool
    {
        $absolute = $this->absolute($path);
        return !is_file($absolute) || unlink($absolute);
    }

    /**
     * Verify a signature previously produced by temporaryUrl(). Useful for a
     * download controller. Uses hash_equals to avoid timing leaks.
     */
    public function verifyTemporaryUrl(string $path, int $expires, string $signature): bool
    {
        if ($this->urlSecret === '' || $expires < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $this->normalise($path) . '|' . $expires, $this->urlSecret);
        return hash_equals($expected, $signature);
    }

    // ── Path safety ──────────────────────────────────────────────────────────

    private function join(string $path, string $filename): string
    {
        $path = trim($path, '/');
        return $path === '' ? $filename : $path . '/' . $filename;
    }

    /** Resolve a caller path to an absolute path inside the root, or throw. */
    private function absolute(string $path): string
    {
        $absolute = $this->root . '/' . $this->normalise($path);

        // normalise() rejects ".." textually, which does NOT stop a SYMLINK
        // inside the root pointing out of it: "uploads/link/secret" contains no
        // traversal segment, yet resolves wherever the link points. Canonicalise
        // and re-check.
        //
        // realpath() returns false for a path that does not exist yet — the
        // normal case when writing — so in that case canonicalise the deepest
        // EXISTING ancestor instead, which is what a symlink would live in.
        $resolved = realpath($absolute);
        if ($resolved === false) {
            $resolved = $this->resolveExistingAncestor($absolute);
        }

        if ($resolved !== null && !$this->isInsideRoot($resolved)) {
            throw new \RuntimeException('Storage: path escapes the storage root.');
        }

        return $absolute;
    }

    /** The canonical path of the deepest existing ancestor of $absolute, or null. */
    private function resolveExistingAncestor(string $absolute): ?string
    {
        $dir = \dirname($absolute);

        while ($dir !== '' && $dir !== '/' && $dir !== '.') {
            $real = realpath($dir);
            if ($real !== false) {
                return $real;
            }
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /** Canonical containment check, comparing WITH a trailing separator. */
    private function isInsideRoot(string $resolved): bool
    {
        $root = realpath($this->root);
        if ($root === false) {
            return true; // root not created yet — nothing to escape from
        }

        // Trailing separator so "/data/storage-evil" is not read as a child of
        // "/data/storage".
        return $resolved === $root || str_starts_with($resolved, rtrim($root, '/') . '/');
    }

    /**
     * Apply the requested visibility, FAILING if it could not be applied.
     *
     * chmod()'s return used to be discarded, so when it failed — a different
     * owner, a filesystem without POSIX permissions, a restrictive umask on a
     * mounted volume — the file stayed 0644 and the caller was told the private
     * write had succeeded. A file believed private and actually world-readable
     * is worse than a failed write.
     */
    private function applyVisibility(string $absolute, string $visibility, string $relative): void
    {
        $mode = $visibility === 'public' ? 0644 : 0600;

        if (@chmod($absolute, $mode)) {
            return;
        }

        // Verify before failing: some filesystems report false yet already have
        // the right mode, and others cannot represent permissions at all.
        $actual = @fileperms($absolute);
        if ($actual !== false && ($actual & 0777) === $mode) {
            return;
        }

        @unlink($absolute); // do not leave a file that is more permissive than requested

        throw new \RuntimeException(sprintf(
            'Storage: could not set %s permissions on [%s]. The file was removed rather than '
            . 'left readable by others.',
            $visibility,
            $relative,
        ));
    }

    /** Reject traversal and normalise to a clean relative path. */
    private function normalise(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new \RuntimeException('Storage: path traversal is not allowed.');
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }
}
