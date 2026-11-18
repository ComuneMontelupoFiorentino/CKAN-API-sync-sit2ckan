<?php

declare(strict_types=1);

namespace CkanScheduler\Lock;

use CkanScheduler\Exception\LockException;

/**
 * Filesystem-based exclusive lock.
 *
 * Uses flock() on a lock file so that only one scheduler process can run
 * at a time. The lock is released automatically when the object is destroyed
 * (or when release() is called explicitly).
 */
final class FileLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $lockPath) {}

    /**
     * Acquire the lock (non-blocking).
     *
     * @throws LockException if another process holds the lock.
     */
    public function acquire(): void
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new LockException("Cannot create lock directory: {$dir}");
        }

        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new LockException("Cannot open lock file: {$this->lockPath}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new LockException(
                "Another scheduler process is already running. " .
                "Lock file: {$this->lockPath}"
            );
        }

        // Stamp PID and start time so operators can inspect the lock file.
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, "pid=" . getmypid() . "\nstarted=" . date(DATE_ATOM) . "\n");
        fflush($handle);

        $this->handle = $handle;
    }

    /** Release the lock and remove the lock file. */
    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;

            if (file_exists($this->lockPath)) {
                @unlink($this->lockPath);
            }
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
