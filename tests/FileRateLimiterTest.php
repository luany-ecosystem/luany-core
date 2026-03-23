<?php

namespace Luany\Core\Tests;

use Luany\Core\RateLimit\FileRateLimiter;
use PHPUnit\Framework\TestCase;

class FileRateLimiterTest extends TestCase
{
    private string $tmpDir;
    private FileRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/luany_rl_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->limiter = new FileRateLimiter($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->limiter->flush();
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ── Constructor ───────────────────────────────────────────────────────────

    public function test_constructor_creates_directory_if_not_exists(): void
    {
        $newDir = $this->tmpDir . '/nested/sub';
        $limiter = new FileRateLimiter($newDir);
        $this->assertDirectoryExists($newDir);
        // Cleanup
        rmdir($newDir);
        rmdir(dirname($newDir));
    }

    // ── attempt() ────────────────────────────────────────────────────────────

    public function test_attempt_allows_first_request(): void
    {
        $this->assertTrue($this->limiter->attempt('test', 5, 60));
    }

    public function test_attempt_allows_up_to_max_attempts(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue($this->limiter->attempt('test', 3, 60), "Attempt {$i} should be allowed");
        }
    }

    public function test_attempt_rejects_after_max_attempts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->limiter->attempt('test', 3, 60);
        }
        $this->assertFalse($this->limiter->attempt('test', 3, 60));
    }

    public function test_attempt_persists_across_instances(): void
    {
        // First instance records 3 hits
        for ($i = 0; $i < 3; $i++) {
            $this->limiter->attempt('persistent', 3, 60);
        }

        // New instance reads the same file
        $limiter2 = new FileRateLimiter($this->tmpDir);
        $this->assertFalse($limiter2->attempt('persistent', 3, 60));
    }

    // ── remaining() ──────────────────────────────────────────────────────────

    public function test_remaining_decrements_with_each_attempt(): void
    {
        $this->limiter->attempt('test', 5, 60);
        $this->assertSame(4, $this->limiter->remaining('test', 5));
    }

    public function test_remaining_returns_max_for_unknown_key(): void
    {
        $this->assertSame(5, $this->limiter->remaining('never-used', 5));
    }

    // ── tooManyAttempts() ────────────────────────────────────────────────────

    public function test_too_many_attempts_true_after_limit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->limiter->attempt('test', 3, 60);
        }
        $this->assertTrue($this->limiter->tooManyAttempts('test', 3));
    }

    public function test_too_many_attempts_false_for_unknown_key(): void
    {
        $this->assertFalse($this->limiter->tooManyAttempts('unknown', 5));
    }

    // ── availableAt() ────────────────────────────────────────────────────────

    public function test_available_at_returns_zero_for_unknown_key(): void
    {
        $this->assertSame(0, $this->limiter->availableAt('unknown'));
    }

    public function test_available_at_is_in_future_after_attempt(): void
    {
        $before = time();
        $this->limiter->attempt('test', 5, 60);
        $at = $this->limiter->availableAt('test');
        $this->assertGreaterThanOrEqual($before + 60, $at);
    }

    // ── reset() ──────────────────────────────────────────────────────────────

    public function test_reset_allows_new_attempts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->limiter->attempt('test', 3, 60);
        }
        $this->limiter->reset('test');
        $this->assertTrue($this->limiter->attempt('test', 3, 60));
    }

    // ── flush() ──────────────────────────────────────────────────────────────

    public function test_flush_removes_all_files(): void
    {
        $this->limiter->attempt('a', 5, 60);
        $this->limiter->attempt('b', 5, 60);

        $this->limiter->flush();

        // Both keys reset to fresh state
        $this->assertSame(5, $this->limiter->remaining('a', 5));
        $this->assertSame(5, $this->limiter->remaining('b', 5));
    }

    // ── File safety ───────────────────────────────────────────────────────────

    public function test_key_with_path_characters_is_hashed_safely(): void
    {
        // A key with slashes should not cause path traversal
        $result = $this->limiter->attempt('../../../etc/passwd', 5, 60);
        $this->assertTrue($result); // Should not throw or create files outside tmpDir
    }
}
