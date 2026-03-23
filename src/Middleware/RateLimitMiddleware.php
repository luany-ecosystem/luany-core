<?php

namespace Luany\Core\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\RateLimit\RateLimiterInterface;

/**
 * RateLimitMiddleware
 *
 * Applies rate limiting to requests using a pluggable RateLimiterInterface.
 *
 * When the limit is exceeded, returns a 429 Too Many Requests response with:
 *   - X-RateLimit-Limit     — configured maximum attempts
 *   - X-RateLimit-Remaining — remaining attempts (0)
 *   - Retry-After           — seconds until the window resets
 *
 * Successful requests also receive the rate limit headers for client awareness.
 *
 * Usage — on a specific route:
 *   Route::post('/login', [AuthController::class, 'login'])
 *        ->middleware(new RateLimitMiddleware($limiter, maxAttempts: 5, decaySeconds: 60));
 *
 * Usage — globally or per route group:
 *   Route::prefix('/api')->middleware([
 *       new RateLimitMiddleware($limiter, maxAttempts: 60, decaySeconds: 60),
 *   ])->group(function () { ... });
 *
 * Key strategy:
 *   The key is built as "{prefix}:{ip}". Override keyFor() in a subclass to
 *   use user ID, API token, or any other identifier.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param RateLimiterInterface $limiter       The underlying rate limiter store
     * @param int                  $maxAttempts   Maximum requests per window
     * @param int                  $decaySeconds  Window length in seconds
     * @param string               $keyPrefix     Prefix for the limiter key (differentiates routes)
     */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly int    $maxAttempts  = 60,
        private readonly int    $decaySeconds = 60,
        private readonly string $keyPrefix    = 'rl',
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $key = $this->keyFor($request);

        $allowed = $this->limiter->attempt($key, $this->maxAttempts, $this->decaySeconds);

        if (!$allowed) {
            return $this->tooManyAttemptsResponse($key);
        }

        $response  = $next($request);
        $remaining = $this->limiter->remaining($key, $this->maxAttempts);

        return $response
            ->header('X-RateLimit-Limit',     (string) $this->maxAttempts)
            ->header('X-RateLimit-Remaining', (string) $remaining);
    }

    /**
     * Build the rate-limit key for this request.
     * Override in a subclass to key by user ID, API token, etc.
     *
     * Default: "{prefix}:{client_ip}"
     */
    protected function keyFor(Request $request): string
    {
        return $this->keyPrefix . ':' . $request->ip();
    }

    /**
     * Build the 429 response when the limit is exceeded.
     */
    private function tooManyAttemptsResponse(string $key): Response
    {
        $retryAfter = max(0, $this->limiter->availableAt($key) - time());

        return Response::make('Too Many Requests', 429)
            ->header('X-RateLimit-Limit',     (string) $this->maxAttempts)
            ->header('X-RateLimit-Remaining', '0')
            ->header('Retry-After',            (string) $retryAfter)
            ->header('Content-Type',           'text/plain; charset=UTF-8');
    }
}
