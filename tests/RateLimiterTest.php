<?php

namespace Luany\Core\Tests;

use Luany\Core\RateLimit\InMemoryRateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private InMemoryRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new InMemoryRateLimiter();
        InMemoryRateLimiter::flush(); // clean slate for every test
    }

    protected function tearDown(): void
    {
        InMemoryRateLimiter::flush();
    }

    // ── attempt() ────────────────────────────────────────────────────────────

    public function test_attempt_allows_first_request(): void
    {
        $this->assertTrue($this->limiter->attempt('test:127.0.0.1', 5, 60));
    }

    public function test_attempt_allows_up_to_max_attempts(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $result = $this->limiter->attempt('test', 5, 60);
            $this->assertTrue($result, "Attempt {$i} should be allowed");
        }
    }

    public function test_attempt_rejects_after_max_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt('test', 5, 60);
        }
        // 6th attempt — over the limit
        $this->assertFalse($this->limiter->attempt('test', 5, 60));
    }

    public function test_different_keys_are_independent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt('key-a', 5, 60);
        }
        // key-b is fresh — should be allowed
        $this->assertTrue($this->limiter->attempt('key-b', 5, 60));
        // key-a is exhausted
        $this->assertFalse($this->limiter->attempt('key-a', 5, 60));
    }

    // ── remaining() ──────────────────────────────────────────────────────────

    public function test_remaining_starts_at_max(): void
    {
        $this->assertSame(5, $this->limiter->remaining('fresh-key', 5));
    }

    public function test_remaining_decrements_with_each_attempt(): void
    {
        $this->limiter->attempt('test', 5, 60);
        $this->assertSame(4, $this->limiter->remaining('test', 5));

        $this->limiter->attempt('test', 5, 60);
        $this->assertSame(3, $this->limiter->remaining('test', 5));
    }

    public function test_remaining_does_not_go_below_zero(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->limiter->attempt('test', 5, 60);
        }
        $this->assertSame(0, $this->limiter->remaining('test', 5));
    }

    // ── tooManyAttempts() ────────────────────────────────────────────────────

    public function test_too_many_attempts_false_when_under_limit(): void
    {
        $this->limiter->attempt('test', 5, 60);
        $this->assertFalse($this->limiter->tooManyAttempts('test', 5));
    }

    public function test_too_many_attempts_true_after_limit_reached(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt('test', 5, 60);
        }
        $this->assertTrue($this->limiter->tooManyAttempts('test', 5));
    }

    public function test_too_many_attempts_false_for_unknown_key(): void
    {
        $this->assertFalse($this->limiter->tooManyAttempts('never-used', 5));
    }

    // ── availableAt() ────────────────────────────────────────────────────────

    public function test_available_at_returns_zero_for_unknown_key(): void
    {
        $this->assertSame(0, $this->limiter->availableAt('unknown'));
    }

    public function test_available_at_returns_future_timestamp(): void
    {
        $before = time();
        $this->limiter->attempt('test', 5, 60);
        $availableAt = $this->limiter->availableAt('test');

        $this->assertGreaterThanOrEqual($before + 60, $availableAt);
        $this->assertLessThanOrEqual($before + 61, $availableAt);
    }

    // ── reset() ──────────────────────────────────────────────────────────────

    public function test_reset_clears_key(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt('test', 5, 60);
        }
        $this->assertTrue($this->limiter->tooManyAttempts('test', 5));

        $this->limiter->reset('test');

        $this->assertFalse($this->limiter->tooManyAttempts('test', 5));
        $this->assertTrue($this->limiter->attempt('test', 5, 60));
    }

    public function test_reset_does_not_affect_other_keys(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt('a', 5, 60);
            $this->limiter->attempt('b', 5, 60);
        }

        $this->limiter->reset('a');

        $this->assertTrue($this->limiter->attempt('a', 5, 60));  // reset
        $this->assertFalse($this->limiter->attempt('b', 5, 60)); // still exhausted
    }

    // ── flush() ──────────────────────────────────────────────────────────────

    public function test_flush_clears_all_keys(): void
    {
        $this->limiter->attempt('x', 5, 60);
        $this->limiter->attempt('y', 5, 60);

        InMemoryRateLimiter::flush();

        $this->assertSame(0, $this->limiter->availableAt('x'));
        $this->assertSame(0, $this->limiter->availableAt('y'));
    }
}
