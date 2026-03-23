<?php

namespace Luany\Core\RateLimit;

/**
 * RateLimiterInterface
 *
 * Contract for all rate limiter implementations.
 *
 * A rate limiter tracks how many times an action has been performed within
 * a sliding time window and rejects requests that exceed the configured limit.
 *
 * Usage in middleware:
 *   $limiter = new InMemoryRateLimiter();  // or FileRateLimiter
 *
 *   $key = 'api:' . $request->ip();
 *
 *   if (!$limiter->attempt($key, maxAttempts: 60, decaySeconds: 60)) {
 *       return Response::make('Too Many Requests', 429)
 *           ->header('Retry-After', (string) $limiter->availableAt($key));
 *   }
 *
 * Pluggable: swap in a Redis-backed or database-backed implementation
 * without changing any middleware or application code.
 */
interface RateLimiterInterface
{
    /**
     * Record a hit for $key and return whether the attempt is allowed.
     *
     * Returns true  if the attempt is within the limit (request may proceed).
     * Returns false if the limit has been exceeded (request should be rejected).
     *
     * @param string $key           Unique identifier for the rate-limited resource
     *                              (e.g. 'login:127.0.0.1', 'api:user:42')
     * @param int    $maxAttempts   Maximum number of attempts allowed in the window
     * @param int    $decaySeconds  Length of the time window in seconds
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool;

    /**
     * Return the number of remaining attempts for $key within the current window.
     * Returns 0 if the limit has been exceeded.
     */
    public function remaining(string $key, int $maxAttempts): int;

    /**
     * Return the Unix timestamp when the rate limit for $key resets.
     * Returns 0 if no hits have been recorded for $key.
     */
    public function availableAt(string $key): int;

    /**
     * Return true if $key has exceeded $maxAttempts in the current window.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool;

    /**
     * Clear all hit counts for $key (reset the counter immediately).
     */
    public function reset(string $key): void;
}
