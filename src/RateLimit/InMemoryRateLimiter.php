<?php

namespace Luany\Core\RateLimit;

/**
 * InMemoryRateLimiter
 *
 * Rate limiter backed by a static in-process array.
 *
 * ┌─────────────────────────────────────────────────────┐
 * │  ⚠  This store is per-process and non-persistent.  │
 * │  Data is lost when the process ends.                │
 * │  State is SHARED across all instances in the same   │
 * │  process (static storage).                          │
 * └─────────────────────────────────────────────────────┘
 *
 * Appropriate for:
 *   - Unit tests (use reset() in tearDown to clean up)
 *   - CLI commands where each invocation is a single process
 *   - Development environments
 *
 * NOT appropriate for:
 *   - Production web servers with multiple PHP workers / processes
 *   - Distributed systems
 *
 * For production use FileRateLimiter (single-server) or a Redis-backed
 * implementation (distributed).
 */
class InMemoryRateLimiter implements RateLimiterInterface
{
    /**
     * Static store shared across all instances in this process.
     *
     * Structure:
     *   $store[$key] = [
     *       'hits'      => int,   // number of hits in the current window
     *       'resetsAt'  => int,   // Unix timestamp when the window expires
     *   ]
     *
     * @var array<string, array{hits: int, resetsAt: int}>
     */
    private static array $store = [];

    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $now = time();

        // Initialise or reset expired window
        if (!isset(self::$store[$key]) || self::$store[$key]['resetsAt'] <= $now) {
            self::$store[$key] = [
                'hits'     => 0,
                'resetsAt' => $now + $decaySeconds,
            ];
        }

        self::$store[$key]['hits']++;

        return self::$store[$key]['hits'] <= $maxAttempts;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        if (!isset(self::$store[$key]) || self::$store[$key]['resetsAt'] <= time()) {
            return $maxAttempts;
        }

        return max(0, $maxAttempts - self::$store[$key]['hits']);
    }

    public function availableAt(string $key): int
    {
        if (!isset(self::$store[$key])) {
            return 0;
        }

        return self::$store[$key]['resetsAt'];
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        if (!isset(self::$store[$key]) || self::$store[$key]['resetsAt'] <= time()) {
            return false;
        }

        return self::$store[$key]['hits'] >= $maxAttempts;
    }

    public function reset(string $key): void
    {
        unset(self::$store[$key]);
    }

    /**
     * Clear all stored keys.
     * Useful in test tearDown() to prevent bleed between test cases.
     */
    public static function flush(): void
    {
        self::$store = [];
    }
}
