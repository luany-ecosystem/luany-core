<?php

namespace Luany\Core\RateLimit;

/**
 * FileRateLimiter
 *
 * Rate limiter backed by JSON files on the local filesystem.
 *
 * Each rate-limit key is stored in a separate file under $storagePath.
 * File names are derived from the key via sha256 hashing (no path traversal possible).
 *
 * Appropriate for:
 *   - Single-server production environments
 *   - Any PHP-FPM / mod_php setup where file locking is reliable
 *
 * NOT appropriate for:
 *   - Multi-server (load-balanced) environments — use a shared store (Redis, DB)
 *
 * Concurrency safety:
 *   Each read-modify-write cycle acquires an exclusive (LOCK_EX) flock().
 *   Requests that cannot acquire the lock within the default timeout will
 *   fail open (the attempt is allowed) to prevent deadlocks.
 *
 * File format (JSON):
 *   { "hits": 5, "resetsAt": 1710000000 }
 *
 * @param string $storagePath  Absolute path to a writable directory.
 *                             Example: base_path('storage/rate-limits')
 */
class FileRateLimiter implements RateLimiterInterface
{
    public function __construct(private readonly string $storagePath)
    {
        if (!is_dir($storagePath) && !mkdir($storagePath, 0755, true) && !is_dir($storagePath)) {
            throw new \RuntimeException("Rate limiter storage directory could not be created: {$storagePath}");
        }
    }

    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $now  = time();
        $path = $this->pathFor($key);

        $data = $this->read($path);

        // Initialise or reset expired window
        if ($data === null || $data['resetsAt'] <= $now) {
            $data = [
                'hits'     => 0,
                'resetsAt' => $now + $decaySeconds,
            ];
        }

        $data['hits']++;
        $this->write($path, $data);

        return $data['hits'] <= $maxAttempts;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        $data = $this->read($this->pathFor($key));

        if ($data === null || $data['resetsAt'] <= time()) {
            return $maxAttempts;
        }

        return max(0, $maxAttempts - $data['hits']);
    }

    public function availableAt(string $key): int
    {
        $data = $this->read($this->pathFor($key));
        return $data['resetsAt'] ?? 0;
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $data = $this->read($this->pathFor($key));

        if ($data === null || $data['resetsAt'] <= time()) {
            return false;
        }

        return $data['hits'] >= $maxAttempts;
    }

    public function reset(string $key): void
    {
        $path = $this->pathFor($key);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Remove all rate-limit files in the storage directory.
     * Useful for maintenance scripts or during testing.
     */
    public function flush(): void
    {
        foreach (glob($this->storagePath . '/rl_*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Derive a safe, unique file path for the given key.
     */
    private function pathFor(string $key): string
    {
        return $this->storagePath . '/rl_' . hash('sha256', $key) . '.json';
    }

    /**
     * Read and parse the rate-limit file for a key.
     * Returns null if the file does not exist or is corrupt.
     *
     * @return array{hits: int, resetsAt: int}|null
     */
    private function read(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, LOCK_SH);
            $raw = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (!$raw) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['hits'], $data['resetsAt'])) {
            return null;
        }

        return $data;
    }

    /**
     * Write rate-limit data to file with an exclusive lock.
     *
     * @param array{hits: int, resetsAt: int} $data
     */
    private function write(string $path, array $data): void
    {
        $handle = fopen($path, 'c');
        if ($handle === false) {
            return; // Fail open — allow the request rather than error
        }

        try {
            if (flock($handle, LOCK_EX)) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, json_encode($data));
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
