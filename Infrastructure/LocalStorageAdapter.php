<?php

declare(strict_types=1);

namespace Plugins\Storage\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\RangeReadableStorage;
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
 *
 * store()/storeStream() bound their write against $maxPutBytes (0 = unlimited)
 * so an attacker-influenced upload size cannot fill the disk; get() bounds its
 * read the same way against $maxGetBytes. Directories are created 0750 (owner+
 * group only) — file-level visibility already governs read access, so a 0775
 * directory would needlessly let "other" list filenames inside it.
 */
final class LocalStorageAdapter implements StoragePort, RangeReadableStorage
{
    private readonly string $root;

    /**
     * @param int $maxGetBytes 0 falls back to a 16 MiB safety cap — get() must
     *                         always bound its buffering, unlike uploads.
     * @param int $maxPutBytes 0 = unlimited (backward-compatible default). Set
     *                         to bound store()/storeStream() against filling
     *                         the disk from attacker-influenced upload sizes.
     */
    public function __construct(
        string $root,
        private readonly string $urlBase = '',
        private readonly string $urlSecret = '',
        private readonly int $maxGetBytes = 16 * 1024 * 1024,
        private readonly int $maxPutBytes = 0,
    ) {
        $resolved = rtrim($root, '/\\');
        $this->root = $resolved === '' ? '/' : $resolved;
    }

    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string
    {
        if ($this->maxPutBytes > 0 && strlen($contents) > $this->maxPutBytes) {
            throw new \RuntimeException(sprintf(
                'Storage: content for [%s] is %d bytes, over the %d-byte store() limit. Use storeStream().',
                $filename,
                strlen($contents),
                $this->maxPutBytes,
            ));
        }

        $relative = $this->join($path, $filename);
        $absolute = $this->absolute($relative);

        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
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
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
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
            // Cap the copy at maxPutBytes+1: a source under the limit reaches
            // EOF before that, so $copied stays <= maxPutBytes; a source AT or
            // OVER it copies exactly maxPutBytes+1, which the check below
            // rejects. This bounds a stream of UNKNOWN length (e.g. a chunked
            // upload) without ever buffering more than one byte past the cap —
            // reject, never silently truncate.
            $copied = $this->maxPutBytes > 0
                ? stream_copy_to_stream($resource, $handle, $this->maxPutBytes + 1)
                : stream_copy_to_stream($resource, $handle);
            if ($copied === false) {
                throw new \RuntimeException("Storage: failed to stream file [{$relative}] (disk full?).");
            }
            if ($this->maxPutBytes > 0 && $copied > $this->maxPutBytes) {
                throw new \RuntimeException(sprintf(
                    'Storage: stream for [%s] is over the %d-byte storeStream() limit.',
                    $relative,
                    $this->maxPutBytes,
                ));
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

        $size = @filesize($absolute);

        if ($size !== false && $size > $this->maxGetBytes) {
            throw new \RuntimeException(sprintf(
                'Storage: file [%s] is %d bytes, over the %d-byte get() limit. Use readStream().',
                $path,
                $size,
                $this->maxGetBytes,
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

    /**
     * A file handle seeked to $offset. It continues to EOF — the contract lets
     * the caller bound the read, so no copy of the range is made here.
     */
    public function readRange(string $path, int $offset, ?int $length = null)
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Storage: readRange offset must not be negative.');
        }
        if ($length !== null && $length < 1) {
            throw new \InvalidArgumentException('Storage: readRange length must be at least 1.');
        }

        $handle = $this->readStream($path);
        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new \RuntimeException("Storage: unable to seek to byte {$offset} of [{$path}].");
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

    /**
     * Byte size of a stored object without reading it — lets a caller decide
     * get() vs readStream() before touching file contents, or answer `Range`.
     */
    public function size(string $path): int
    {
        $absolute = $this->absolute($path);
        $size     = @filesize($absolute);
        if ($size === false) {
            throw new \RuntimeException("Storage: file [{$path}] not found.");
        }
        return $size;
    }

    /**
     * Adapter-native extra: not part of StoragePort, so a caller depending on
     * the port alone cannot use it. Fine for code that already knows it is
     * talking to the local driver (an admin CLI, a cleanup job); a Service
     * behind the port must not narrow to this concrete class.
     */
    public function lastModified(string $path): int
    {
        $absolute = $this->absolute($path);
        $time     = @filemtime($absolute);
        if ($time === false) {
            throw new \RuntimeException("Storage: file [{$path}] not found.");
        }
        return $time;
    }

    /** @see lastModified() — same adapter-native-extra caveat applies. */
    public function mimeType(string $path): string
    {
        $absolute = $this->absolute($path);
        if (!is_file($absolute)) {
            throw new \RuntimeException("Storage: file [{$path}] not found.");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new \RuntimeException('Storage: unable to initialise fileinfo.');
        }
        try {
            $mime = finfo_file($finfo, $absolute);
        } finally {
            finfo_close($finfo);
        }
        if ($mime === false) {
            throw new \RuntimeException("Storage: unable to determine mime type of [{$path}].");
        }
        return $mime;
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
