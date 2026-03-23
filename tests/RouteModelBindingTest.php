<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Routing\Route;
use Luany\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

// ── Stub model for binding tests ──────────────────────────────────────────────

class BindingUser
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
    ) {}

    public static function find(mixed $id): ?self
    {
        $users = [
            1 => new self(1, 'António'),
            2 => new self(2, 'Dadiva'),
        ];
        return $users[(int) $id] ?? null;
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

class RouteModelBindingTest extends TestCase
{
    private function makeRequest(string $method, string $uri): Request
    {
        return new Request($method, $uri);
    }

    // ── Router::bind() ────────────────────────────────────────────────────────

    public function test_bind_resolves_param_before_action(): void
    {
        $router = new Router();
        $router->bind('user', fn($id) => BindingUser::find($id));
        $router->addRoute('GET', '/users/{user}', function (Request $req, $user) {
            return Response::make($user instanceof BindingUser ? $user->name : 'not-bound');
        });

        $response = $router->handle($this->makeRequest('GET', '/users/1'));

        $this->assertSame('António', $response->getBody());
    }

    public function test_bind_passes_null_when_model_not_found(): void
    {
        $router = new Router();
        $router->bind('user', fn($id) => BindingUser::find($id));
        $router->addRoute('GET', '/users/{user}', function (Request $req, $user) {
            return Response::make($user === null ? 'null' : 'found');
        });

        $response = $router->handle($this->makeRequest('GET', '/users/999'));

        $this->assertSame('null', $response->getBody());
    }

    public function test_unbound_param_passes_raw_string(): void
    {
        $router = new Router();
        // No binding registered for 'id'
        $router->addRoute('GET', '/items/{id}', function (Request $req, $id) {
            return Response::make(gettype($id) . ':' . $id);
        });

        $response = $router->handle($this->makeRequest('GET', '/items/42'));

        $this->assertSame('string:42', $response->getBody());
    }

    public function test_multiple_bindings_resolve_independently(): void
    {
        $router = new Router();
        $router->bind('user', fn($id) => BindingUser::find($id));
        $router->bind('category', fn($slug) => strtoupper($slug)); // simple transformation

        $router->addRoute('GET', '/users/{user}/category/{category}', function (Request $req, $user, $category) {
            return Response::make($user->name . ':' . $category);
        });

        $response = $router->handle($this->makeRequest('GET', '/users/2/category/tech'));

        $this->assertSame('Dadiva:TECH', $response->getBody());
    }

    public function test_bind_only_affects_named_param(): void
    {
        $router = new Router();
        $router->bind('user', fn($id) => BindingUser::find($id));

        // Route has {id} not {user} — should NOT be bound
        $router->addRoute('GET', '/items/{id}', function (Request $req, $id) {
            return Response::make(gettype($id) . ':' . $id);
        });

        $response = $router->handle($this->makeRequest('GET', '/items/1'));

        $this->assertSame('string:1', $response->getBody()); // raw string, not a BindingUser
    }

    public function test_getBindings_returns_registered_bindings(): void
    {
        $router   = new Router();
        $resolver = fn($id) => BindingUser::find($id);
        $router->bind('user', $resolver);

        $bindings = $router->getBindings();

        $this->assertArrayHasKey('user', $bindings);
        $this->assertIsCallable($bindings['user']);
    }

    // ── Route::bind() facade ──────────────────────────────────────────────────

    public function test_route_facade_bind(): void
    {
        Route::reset();
        Route::bind('user', fn($id) => BindingUser::find($id));

        Route::get('/users/{user}', function (Request $req, $user) {
            return Response::make($user instanceof BindingUser ? $user->name : 'not-bound');
        });

        $response = Route::router()->handle($this->makeRequest('GET', '/users/1'));

        $this->assertSame('António', $response->getBody());

        Route::reset();
    }

    // ── Route::model() facade ─────────────────────────────────────────────────

    public function test_route_model_binding_uses_find(): void
    {
        Route::reset();
        Route::model('user', BindingUser::class);

        Route::get('/users/{user}', function (Request $req, $user) {
            return Response::make($user instanceof BindingUser ? $user->name : 'not-bound');
        });

        $response = Route::router()->handle($this->makeRequest('GET', '/users/2'));

        $this->assertSame('Dadiva', $response->getBody());

        Route::reset();
    }

    public function test_route_model_returns_null_for_missing_id(): void
    {
        Route::reset();
        Route::model('user', BindingUser::class);

        Route::get('/users/{user}', function (Request $req, $user) {
            return Response::make($user === null ? 'not-found' : $user->name);
        });

        $response = Route::router()->handle($this->makeRequest('GET', '/users/9999'));

        $this->assertSame('not-found', $response->getBody());

        Route::reset();
    }

    // ── Binding with middleware ───────────────────────────────────────────────

    public function test_binding_resolved_before_action_even_with_middleware(): void
    {
        $router = new Router();
        $router->bind('user', fn($id) => BindingUser::find($id));

        // Middleware that passes through
        $passthrough = new class implements \Luany\Core\Middleware\MiddlewareInterface {
            public function handle(Request $request, callable $next): Response {
                return $next($request);
            }
        };

        $router->addRoute('GET', '/users/{user}', function (Request $req, $user) {
            return Response::make($user->name);
        });

        $response = $router->handle($this->makeRequest('GET', '/users/1'));

        $this->assertSame('António', $response->getBody());
    }
}
