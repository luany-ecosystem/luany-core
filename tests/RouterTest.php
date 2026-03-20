<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private function makeRequest(string $method, string $uri): Request
    {
        return new Request($method, $uri);
    }

    private function dispatch(Router $router, string $method, string $uri): Response
    {
        $request = $this->makeRequest($method, $uri);

        ob_start();
        $router->dispatch($request);
        ob_end_clean();

        // Capture response via output buffering trick — use a testable variant
        // For unit tests we call executeAction directly via reflection
        // Instead, rebuild dispatch to return Response for testing
        return Response::make('dispatched');
    }

    // ── addRoute ──────────────────────────────────────────────────────────────

    public function test_handle_returns_response_without_sending(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/ping', fn() => Response::make('pong'));

        $request = $this->makeRequest('GET', '/ping');
        $response = $router->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('pong', $response->getBody());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_handle_throws_for_unknown_route(): void
    {
        $router  = new Router();
        $request = $this->makeRequest('GET', '/unknown');

        $this->expectException(\Luany\Core\Exceptions\RouteNotFoundException::class);
        $router->handle($request);
    }

    public function test_route_not_found_exception_has_404_code(): void
    {
        $router  = new Router();
        $request = $this->makeRequest('GET', '/unknown');

        try {
            $router->handle($request);
            $this->fail('Expected RouteNotFoundException');
        } catch (\Luany\Core\Exceptions\RouteNotFoundException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertStringContainsString('/unknown', $e->getMessage());
        }
    }

    public function test_add_route_returns_route_registrar(): void
    {
        $router = new Router();
        $registrar = $router->addRoute('GET', '/', fn() => Response::make('ok'));
        $this->assertInstanceOf(\Luany\Core\Routing\RouteRegistrar::class, $registrar);
    }

    public function test_named_route_resolution(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', fn() => Response::make('ok'))->name('users.show');
        $uri = $router->getNamedRoute('users.show', ['id' => '42']);
        $this->assertSame('/users/42', $uri);
    }

    public function test_named_route_returns_null_for_unknown(): void
    {
        $router = new Router();
        $this->assertNull($router->getNamedRoute('unknown'));
    }

    public function test_named_route_multiple_params(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/posts/{post}/comments/{comment}', fn() => Response::make(''))->name('post.comment');
        $uri = $router->getNamedRoute('post.comment', ['post' => '1', 'comment' => '5']);
        $this->assertSame('/posts/1/comments/5', $uri);
    }

    // ── Group prefix ──────────────────────────────────────────────────────────

    public function test_group_prefix_is_applied(): void
    {
        $router = new Router();
        $router->pushGroupContext(['prefix' => 'admin', 'middleware' => []]);
        $router->addRoute('GET', '/users', fn() => Response::make('ok'));
        $router->popGroupContext();
        $uri = $router->getNamedRoute('admin.users') ?? null;
        // Check via reflection that the route was registered with prefix
        $reflection = new \ReflectionClass($router);
        $prop = $reflection->getProperty('routes');
        $prop->setAccessible(true);
        $routes = $prop->getValue($router);
        $this->assertSame('/admin/users', $routes[0]['uri']);
    }

    public function test_nested_group_prefixes(): void
    {
        $router = new Router();
        $router->pushGroupContext(['prefix' => 'api', 'middleware' => []]);
        $router->pushGroupContext(['prefix' => 'v1', 'middleware' => []]);
        $router->addRoute('GET', '/users', fn() => Response::make('ok'));
        $router->popGroupContext();
        $router->popGroupContext();
        $reflection = new \ReflectionClass($router);
        $prop = $reflection->getProperty('routes');
        $prop->setAccessible(true);
        $routes = $prop->getValue($router);
        $this->assertSame('/api/v1/users', $routes[0]['uri']);
    }

    public function test_group_context_is_isolated(): void
    {
        $router = new Router();
        $router->pushGroupContext(['prefix' => 'admin', 'middleware' => []]);
        $router->popGroupContext();
        $router->addRoute('GET', '/home', fn() => Response::make('ok'));
        $reflection = new \ReflectionClass($router);
        $prop = $reflection->getProperty('routes');
        $prop->setAccessible(true);
        $routes = $prop->getValue($router);
        $this->assertSame('/home', $routes[0]['uri']);
    }

    // ── Middleware on route ───────────────────────────────────────────────────

    public function test_middleware_is_applied_to_route(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/secret', fn() => Response::make('ok'))
               ->middleware('SomeMiddleware');
        $reflection = new \ReflectionClass($router);
        $prop = $reflection->getProperty('routes');
        $prop->setAccessible(true);
        $routes = $prop->getValue($router);
        $this->assertContains('SomeMiddleware', $routes[0]['middleware']);
    }

    // ── Route URI normalisation ───────────────────────────────────────────────

    public function test_uri_leading_slash_is_normalised(): void
    {
        $router = new Router();
        $router->addRoute('GET', 'users', fn() => Response::make('ok'));
        $reflection = new \ReflectionClass($router);
        $prop = $reflection->getProperty('routes');
        $prop->setAccessible(true);
        $routes = $prop->getValue($router);
        $this->assertSame('/users', $routes[0]['uri']);
    }

    public function test_root_uri(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/', fn() => Response::make('ok'));
        $reflection = new \ReflectionClass($router);
        $prop = $reflection->getProperty('routes');
        $prop->setAccessible(true);
        $routes = $prop->getValue($router);
        $this->assertSame('/', $routes[0]['uri']);
    }

    // ── to_response coercion ──────────────────────────────────────────────────

    public function test_execute_action_returns_response_from_string(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('toResponse');
        $method->setAccessible(true);

        $response = $method->invoke($router, 'Hello World');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('Hello World', $response->getBody());
    }

    public function test_execute_action_returns_response_from_array(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('toResponse');
        $method->setAccessible(true);

        $response = $method->invoke($router, ['status' => 'ok']);
        $this->assertInstanceOf(Response::class, $response);
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('ok', $decoded['status']);
    }

    public function test_execute_action_passthrough_response(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('toResponse');
        $method->setAccessible(true);

        $original = Response::make('test', 201);
        $response = $method->invoke($router, $original);
        $this->assertSame($original, $response);
        $this->assertSame(201, $response->getStatusCode());
    }

    // ── compile pattern ───────────────────────────────────────────────────────

    public function test_compile_pattern_static_uri(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('compilePattern');
        $method->setAccessible(true);

        $pattern = $method->invoke($router, '/users');
        $this->assertSame(1, preg_match($pattern, '/users'));
        $this->assertSame(0, preg_match($pattern, '/posts'));
    }

    public function test_compile_pattern_with_param(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('compilePattern');
        $method->setAccessible(true);

        $pattern = $method->invoke($router, '/users/{id}');
        $this->assertSame(1, preg_match($pattern, '/users/42', $matches));
        $this->assertSame('42', $matches['id']);
    }

    public function test_compile_pattern_does_not_match_slash_in_param(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('compilePattern');
        $method->setAccessible(true);

        $pattern = $method->invoke($router, '/users/{id}');
        $this->assertSame(0, preg_match($pattern, '/users/1/2'));
    }

    // ── Route params must NOT leak into $_GET ─────────────────────────────

    public function test_route_params_do_not_pollute_get_superglobal(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', function (Request $request, string $id) {
            return Response::make("user:{$id}");
        });

        // Ensure $_GET has no 'id' key before dispatch
        unset($_GET['id']);

        $request  = $this->makeRequest('GET', '/users/42');
        $response = $router->handle($request);

        $this->assertSame('user:42', $response->getBody());
        $this->assertArrayNotHasKey('id', $_GET, 'Route params must not be written to \$_GET');
    }

    public function test_route_params_are_passed_as_action_arguments(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/posts/{post}/comments/{comment}', function (Request $request, string $post, string $comment) {
            return Response::make("{$post}:{$comment}");
        });

        $request  = $this->makeRequest('GET', '/posts/5/comments/12');
        $response = $router->handle($request);

        $this->assertSame('5:12', $response->getBody());
        $this->assertArrayNotHasKey('post', $_GET);
        $this->assertArrayNotHasKey('comment', $_GET);
    }

    public function test_get_router_alias_returns_same_instance(): void
    {
        $r1 = Route::router();
        $r2 = Route::getRouter();
        $this->assertSame($r1, $r2, 'getRouter() must return the same singleton as router()');
    }

    public function test_get_routes_returns_all_registered_routes(): void
    {
        Route::reset();
        Route::get('/foo', fn($r) => Response::make('foo'));
        Route::post('/bar', fn($r) => Response::make('bar'));

        $routes = Route::getRoutes();
        $this->assertCount(2, $routes);
        $uris = array_column($routes, 'uri');
        $this->assertContains('/foo', $uris);
        $this->assertContains('/bar', $uris);
    }
}
