<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\RateLimitMiddleware;
use Luany\Core\RateLimit\InMemoryRateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimitMiddlewareTest extends TestCase
{
    private InMemoryRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new InMemoryRateLimiter();
        InMemoryRateLimiter::flush();
    }

    protected function tearDown(): void
    {
        InMemoryRateLimiter::flush();
    }

    private function makeRequest(string $ip = '127.0.0.1'): Request
    {
        return new Request('GET', '/api/data', [], [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    private function next(): callable
    {
        return fn(Request $req) => Response::make('data', 200);
    }

    // ── Allowed requests ──────────────────────────────────────────────────────

    public function test_request_within_limit_is_allowed(): void
    {
        $middleware = new RateLimitMiddleware($this->limiter, maxAttempts: 5, decaySeconds: 60);
        $response   = $middleware->handle($this->makeRequest(), $this->next());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('data', $response->getBody());
    }

    public function test_allowed_response_has_ratelimit_headers(): void
    {
        $middleware = new RateLimitMiddleware($this->limiter, maxAttempts: 5, decaySeconds: 60);
        $response   = $middleware->handle($this->makeRequest(), $this->next());

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('X-RateLimit-Limit',     $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertSame('5', $headers['X-RateLimit-Limit']);
        $this->assertSame('4', $headers['X-RateLimit-Remaining']); // 1 used
    }

    public function test_remaining_decrements_across_requests(): void
    {
        $middleware = new RateLimitMiddleware($this->limiter, maxAttempts: 5, decaySeconds: 60);

        $middleware->handle($this->makeRequest(), $this->next()); // remaining: 4
        $response = $middleware->handle($this->makeRequest(), $this->next()); // remaining: 3

        $this->assertSame('3', $response->getHeaders()['X-RateLimit-Remaining']);
    }

    // ── Rejected requests ─────────────────────────────────────────────────────

    public function test_request_over_limit_returns_429(): void
    {
        $middleware = new RateLimitMiddleware($this->limiter, maxAttempts: 2, decaySeconds: 60);

        $middleware->handle($this->makeRequest(), $this->next());
        $middleware->handle($this->makeRequest(), $this->next());
        $response = $middleware->handle($this->makeRequest(), $this->next()); // 3rd — over limit

        $this->assertSame(429, $response->getStatusCode());
    }

    public function test_429_response_has_retry_after_header(): void
    {
        $middleware = new RateLimitMiddleware($this->limiter, maxAttempts: 1, decaySeconds: 60);

        $middleware->handle($this->makeRequest(), $this->next()); // allowed
        $response = $middleware->handle($this->makeRequest(), $this->next()); // rejected

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Retry-After', $headers);
        $retryAfter = (int) $headers['Retry-After'];
        $this->assertGreaterThanOrEqual(0,  $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    public function test_429_response_has_ratelimit_remaining_zero(): void
    {
        $middleware = new RateLimitMiddleware($this->limiter, maxAttempts: 1, decaySeconds: 60);

        $middleware->handle($this->makeRequest(), $this->next());
        $response = $middleware->handle($this->makeRequest(), $this->next());

        $this->assertSame('0', $response->getHeaders()['X-RateLimit-Remaining']);
    }

    public function test_429_pipeline_short_circuits(): void
    {
        $called     = false;
        $middleware = new RateLimitMiddleware($this->limiter, maxAttempts: 1, decaySeconds: 60);

        $middleware->handle($this->makeRequest(), $this->next()); // uses the 1 allowed attempt

        $middleware->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return Response::make('should not run');
        });

        $this->assertFalse($called);
    }

    // ── Key isolation ─────────────────────────────────────────────────────────

    public function test_different_ips_have_independent_limits(): void
    {
        $middleware = new RateLimitMiddleware($this->limiter, maxAttempts: 2, decaySeconds: 60);

        $middleware->handle($this->makeRequest('10.0.0.1'), $this->next());
        $middleware->handle($this->makeRequest('10.0.0.1'), $this->next());
        $rejected = $middleware->handle($this->makeRequest('10.0.0.1'), $this->next());

        // 10.0.0.2 is untouched — should still be allowed
        $allowed = $middleware->handle($this->makeRequest('10.0.0.2'), $this->next());

        $this->assertSame(429, $rejected->getStatusCode());
        $this->assertSame(200, $allowed->getStatusCode());
    }

    // ── Key prefix ────────────────────────────────────────────────────────────

    public function test_key_prefix_isolates_routes(): void
    {
        $mwLogin = new RateLimitMiddleware($this->limiter, maxAttempts: 1, decaySeconds: 60, keyPrefix: 'login');
        $mwApi   = new RateLimitMiddleware($this->limiter, maxAttempts: 1, decaySeconds: 60, keyPrefix: 'api');

        $mwLogin->handle($this->makeRequest(), $this->next()); // exhausts login:ip

        // api:ip is independent — still allowed
        $response = $mwApi->handle($this->makeRequest(), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }
}
