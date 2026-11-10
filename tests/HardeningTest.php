<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Core\Middleware\Pipeline;
use Luany\Core\RateLimit\FileRateLimiter;
use Luany\Core\Routing\Route;
use Luany\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class HardeningDependency
{
    public string $value = 'injected';
}

final class HardeningMiddleware implements MiddlewareInterface
{
    public function __construct(private HardeningDependency $dep) {}

    public function handle(Request $request, callable $next): Response
    {
        return $next($request)->header('X-Dep', $this->dep->value);
    }
}

final class HardeningController
{
    public function __construct(private HardeningDependency $dep) {}

    public function show(Request $request, string $id): Response
    {
        return Response::make("{$this->dep->value}:{$id}");
    }
}

/**
 * Regression tests for the luany-core hardening issues (#2–#12).
 */
class HardeningTest extends TestCase
{
    protected function setUp(): void
    {
        Route::reset();
        Pipeline::setDefaultResolver(null);
        Request::setTrustedProxies([]);
    }

    protected function tearDown(): void
    {
        Pipeline::setDefaultResolver(null);
        Request::setTrustedProxies([]);
        Route::reset();
        $_GET = [];
    }

    private function resolver(): \Closure
    {
        return function (string $class): object {
            if ($class === HardeningDependency::class) {
                return new HardeningDependency();
            }
            $ref  = new \ReflectionClass($class);
            $ctor = $ref->getConstructor();
            if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
                return new $class();
            }
            return $ref->newInstance(new HardeningDependency());
        };
    }

    // ── #2 resolver hook ─────────────────────────────────────────────────────

    public function test_router_resolver_injects_controller_and_middleware_dependencies(): void
    {
        $router = new Router();
        $router->setResolver($this->resolver());
        $router->addRoute('GET', '/items/{id}', [HardeningController::class, 'show'])
            ->middleware(HardeningMiddleware::class);

        $response = $router->handle(new Request('GET', '/items/7'));

        $this->assertSame('injected:7', $response->getBody());
        $this->assertSame('injected', $response->getHeaders()['X-Dep']);
    }

    public function test_default_pipeline_resolver_is_used_when_router_has_none(): void
    {
        Pipeline::setDefaultResolver($this->resolver());

        $response = (new Pipeline())
            ->send(new Request('GET', '/'))
            ->through([HardeningMiddleware::class])
            ->then(fn () => Response::make('ok'));

        $this->assertSame('injected', $response->getHeaders()['X-Dep']);
    }

    // ── #3 Route::view must not merge $_GET ──────────────────────────────────

    public function test_route_view_ignores_query_string(): void
    {
        Route::setViewRenderer(fn (string $v, array $d) => json_encode($d));
        Route::view('/panel', 'panel', ['isAdmin' => false]);

        $_GET = ['isAdmin' => '1', 'x' => 'y'];
        $response = Route::handle(new Request('GET', '/panel', ['isAdmin' => '1']));

        $this->assertSame('{"isAdmin":false}', $response->getBody());
    }

    // ── #4 trusted proxies ───────────────────────────────────────────────────

    public function test_forwarded_for_ignored_without_trusted_proxy(): void
    {
        $r = new Request('GET', '/', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
        $this->assertSame('203.0.113.9', $r->ip());
    }

    public function test_forwarded_for_honoured_from_trusted_proxy(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8']);

        $r = new Request('GET', '/', server: [
            'REMOTE_ADDR'          => '10.1.2.3',
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 10.0.0.5',
        ]);

        // Right-most untrusted hop is the client; the spoofed left entry is ignored.
        $this->assertSame('198.51.100.7', $r->ip());
    }

    public function test_forwarded_proto_only_from_trusted_proxy(): void
    {
        $untrusted = new Request('GET', '/', server: ['REMOTE_ADDR' => '1.1.1.1', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        $this->assertFalse($untrusted->isSecure());

        Request::setTrustedProxies(['127.0.0.1', '::1']);
        $trusted = new Request('GET', '/', server: ['REMOTE_ADDR' => '::1', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        $this->assertTrue($trusted->isSecure());
    }

    // ── #6 HEAD ──────────────────────────────────────────────────────────────

    public function test_head_matches_get_route_without_body(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/ping', fn () => Response::make('pong')->header('X-A', '1'));

        $response = $router->handle(new Request('HEAD', '/ping'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
        $this->assertSame('1', $response->getHeaders()['X-A']);
    }

    // ── #7 regex escaping ────────────────────────────────────────────────────

    public function test_static_segments_are_literal(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/v1.0/items', fn () => 'ok');

        $this->assertSame('ok', $router->handle(new Request('GET', '/v1.0/items'))->getBody());

        $this->expectException(\Luany\Core\Exceptions\RouteNotFoundException::class);
        $router->handle(new Request('GET', '/v1X0/items'));
    }

    // ── #8 sub-folder base path ──────────────────────────────────────────────

    public function test_strip_base_path_respects_segment_boundary(): void
    {
        $this->assertSame('/x', Request::stripBasePath('/app/x', '/app/index.php'));
        $this->assertSame('/', Request::stripBasePath('/app', '/app/index.php'));
        $this->assertSame('/application/x', Request::stripBasePath('/application/x', '/app/index.php'));
        $this->assertSame('/x', Request::stripBasePath('/x', '/index.php'));
    }

    // ── #10 Response ─────────────────────────────────────────────────────────

    public function test_json_with_invalid_utf8_is_not_empty(): void
    {
        $response = Response::json(['name' => "bad\xB1byte"]);
        $this->assertNotSame('', $response->getBody());
        $this->assertStringContainsString('bad', $response->getBody());
    }

    public function test_multiple_cookies_are_kept(): void
    {
        $response = Response::make()
            ->cookie('a', '1')
            ->cookie('b', 'x y', sameSite: 'Strict');

        $cookies = $response->getCookies();
        $this->assertCount(2, $cookies);
        $this->assertSame('a=1; Path=/; HttpOnly; SameSite=Lax', $cookies[0]);
        $this->assertSame('b=x%20y; Path=/; HttpOnly; SameSite=Strict', $cookies[1]);
    }

    public function test_cookie_name_is_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::make()->cookie("bad\r\nname", 'x');
    }

    // ── #11 named route URLs ─────────────────────────────────────────────────

    public function test_named_route_encodes_params_and_appends_query(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/files/{name}', fn () => '', 'files.show');

        $this->assertSame(
            '/files/..%2Fadmin%3Fx%3D1?page=2',
            $router->getNamedRoute('files.show', ['name' => '../admin?x=1', 'page' => 2])
        );
    }

    public function test_named_route_missing_param_throws(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', fn () => '', 'users.show');

        $this->expectException(\InvalidArgumentException::class);
        $router->getNamedRoute('users.show');
    }

    // ── #12 rate limiter ─────────────────────────────────────────────────────

    public function test_file_rate_limiter_counts_every_attempt(): void
    {
        $dir     = sys_get_temp_dir() . '/luany_rl_' . bin2hex(random_bytes(4));
        $limiter = new FileRateLimiter($dir);

        for ($i = 0; $i < 5; $i++) {
            $limiter->attempt('k', 3, 60);
        }

        $this->assertTrue($limiter->tooManyAttempts('k', 3));
        $this->assertSame(0, $limiter->remaining('k', 3));

        $limiter->flush();
        @rmdir($dir);
    }
}
