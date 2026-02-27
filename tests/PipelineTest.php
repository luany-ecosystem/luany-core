<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Core\Middleware\Pipeline;
use PHPUnit\Framework\TestCase;

// ── Test middleware classes ────────────────────────────────────────────────────

class AppendMiddleware implements MiddlewareInterface
{
    public function __construct(private string $tag) {}

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $response->body($response->getBody() . "[{$this->tag}]");
    }
}

class BlockMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        return Response::forbidden('blocked');
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

class PipelineTest extends TestCase
{
    private function request(): Request
    {
        return new Request('GET', '/');
    }

    public function test_pipeline_with_no_middleware_calls_destination(): void
    {
        $response = (new Pipeline())
            ->send($this->request())
            ->through([])
            ->then(fn() => Response::make('hello'));

        $this->assertSame('hello', $response->getBody());
    }

    public function test_middleware_wraps_destination(): void
    {
        $response = (new Pipeline())
            ->send($this->request())
            ->through([new AppendMiddleware('A')])
            ->then(fn() => Response::make('core'));

        $this->assertSame('core[A]', $response->getBody());
    }

    public function test_middleware_order_is_outer_to_inner(): void
    {
        // A wraps B wraps destination
        // destination runs first, then B appends, then A appends
        $response = (new Pipeline())
            ->send($this->request())
            ->through([new AppendMiddleware('A'), new AppendMiddleware('B')])
            ->then(fn() => Response::make('core'));

        $this->assertSame('core[B][A]', $response->getBody());
    }

    public function test_blocking_middleware_short_circuits(): void
    {
        $called = false;

        $response = (new Pipeline())
            ->send($this->request())
            ->through([new BlockMiddleware()])
            ->then(function () use (&$called) {
                $called = true;
                return Response::make('should not reach');
            });

        $this->assertFalse($called);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('blocked', $response->getBody());
    }

    public function test_middleware_receives_request(): void
    {
        $capturedUri = null;

        $capturingMiddleware = new class($capturedUri) implements MiddlewareInterface {
            public function __construct(private mixed &$captured) {}
            public function handle(Request $request, callable $next): Response
            {
                $this->captured = $request->uri();
                return $next($request);
            }
        };

        (new Pipeline())
            ->send(new Request('GET', '/test-uri'))
            ->through([$capturingMiddleware])
            ->then(fn() => Response::make('ok'));

        $this->assertSame('/test-uri', $capturedUri);
    }

    public function test_invalid_middleware_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        (new Pipeline())
            ->send($this->request())
            ->through(['NonExistentMiddlewareClass'])
            ->then(fn() => Response::make('ok'));
    }

    public function test_class_not_implementing_interface_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        // stdClass does not implement MiddlewareInterface
        (new Pipeline())
            ->send($this->request())
            ->through([\stdClass::class])
            ->then(fn() => Response::make('ok'));
    }
}