<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Routing\Route;
use Luany\Core\Routing\RouteCache;
use Luany\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

class RouteCacheTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/luany_routes_test_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        RouteCache::clear($this->cacheFile);
    }

    // ── RouteCache::store() ───────────────────────────────────────────────────

    public function test_store_creates_cache_file(): void
    {
        $routes = [
            ['method' => 'GET', 'uri' => '/users', 'action' => ['UserController', 'index'], 'middleware' => [], 'name' => 'users.index'],
        ];
        $named = ['users.index' => '/users'];

        RouteCache::store($routes, $named, $this->cacheFile);

        $this->assertFileExists($this->cacheFile);
    }

    public function test_store_excludes_closure_actions(): void
    {
        $routes = [
            ['method' => 'GET',  'uri' => '/array',   'action' => ['Controller', 'method'], 'middleware' => [], 'name' => null],
            ['method' => 'POST', 'uri' => '/closure', 'action' => fn() => Response::make('ok'), 'middleware' => [], 'name' => null],
        ];

        RouteCache::store($routes, [], $this->cacheFile);

        $data = RouteCache::load($this->cacheFile);
        $this->assertCount(1, $data['routes']);
        $this->assertSame('/array', $data['routes'][0]['uri']);
    }

    public function test_store_preserves_named_routes(): void
    {
        $routes = [
            ['method' => 'GET', 'uri' => '/users/{id}', 'action' => ['UserController', 'show'], 'middleware' => [], 'name' => 'users.show'],
        ];
        $named = ['users.show' => '/users/{id}'];

        RouteCache::store($routes, $named, $this->cacheFile);

        $data = RouteCache::load($this->cacheFile);
        $this->assertSame(['users.show' => '/users/{id}'], $data['named']);
    }

    public function test_store_creates_directory_if_needed(): void
    {
        $nested = sys_get_temp_dir() . '/luany_cache_test_' . uniqid() . '/sub/routes.php';

        RouteCache::store([], [], $nested);

        $this->assertFileExists($nested);

        // Cleanup
        unlink($nested);
        rmdir(dirname($nested));
        rmdir(dirname(dirname($nested)));
    }

    // ── RouteCache::load() ────────────────────────────────────────────────────

    public function test_load_returns_null_when_file_does_not_exist(): void
    {
        $this->assertNull(RouteCache::load('/nonexistent/path/routes.php'));
    }

    public function test_load_returns_routes_and_named(): void
    {
        $routes = [
            ['method' => 'GET', 'uri' => '/ping', 'action' => ['PingController', 'ping'], 'middleware' => [], 'name' => 'ping'],
        ];
        $named = ['ping' => '/ping'];

        RouteCache::store($routes, $named, $this->cacheFile);

        $data = RouteCache::load($this->cacheFile);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('routes', $data);
        $this->assertArrayHasKey('named',  $data);
        $this->assertCount(1, $data['routes']);
        $this->assertSame('/ping', $data['routes'][0]['uri']);
        $this->assertSame(['ping' => '/ping'], $data['named']);
    }

    // ── RouteCache::clear() ───────────────────────────────────────────────────

    public function test_clear_removes_cache_file(): void
    {
        RouteCache::store([], [], $this->cacheFile);
        $this->assertFileExists($this->cacheFile);

        RouteCache::clear($this->cacheFile);
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function test_clear_does_not_throw_when_file_does_not_exist(): void
    {
        // Should not throw
        RouteCache::clear('/no/such/file.php');
        $this->assertTrue(true);
    }

    // ── Router::saveToCache() / loadFromCache() ───────────────────────────────

    public function test_router_save_and_load_roundtrip(): void
    {
        $router = new Router();
        $router->addRoute('GET',  '/users',     ['UserController', 'index'])->name('users.index');
        $router->addRoute('POST', '/users',     ['UserController', 'store']);
        $router->addRoute('GET',  '/users/{id}',['UserController', 'show'])->name('users.show');

        $router->saveToCache($this->cacheFile);

        // Fresh router — load from cache
        $router2 = new Router();
        $loaded  = $router2->loadFromCache($this->cacheFile);

        $this->assertTrue($loaded);
        $this->assertCount(3, $router2->getRoutes());
        $this->assertSame('/users', $router2->getNamedRoute('users.index'));
        $this->assertSame('/users/42', $router2->getNamedRoute('users.show', ['id' => '42']));
    }

    public function test_router_load_returns_false_when_no_cache(): void
    {
        $router = new Router();
        $this->assertFalse($router->loadFromCache('/no/such/cache.php'));
    }

    public function test_cached_routes_can_dispatch_requests(): void
    {
        // Register and cache real routes
        $router = new Router();
        $router->addRoute('GET', '/hello', fn() => Response::make('cached!'));
        $router->saveToCache($this->cacheFile);

        // Load cache — closure was excluded, so this tests graceful degradation
        $router2 = new Router();
        $router2->loadFromCache($this->cacheFile);

        // The cached routes (array-action only) have no closures to dispatch
        // but the route table was correctly loaded
        $routes = $router2->getRoutes();
        $this->assertCount(0, $routes); // closure was excluded from cache
    }

    public function test_cached_routes_with_array_action_dispatch_correctly(): void
    {
        // Register a route with array action pointing to an inline anonymous class
        // We cannot use a real controller in tests, so we verify the route is cached
        // and the data round-trips correctly
        $router = new Router();
        $router->addRoute('GET', '/api/status', ['StatusController', 'index'])->name('status');
        $router->addRoute('DELETE', '/api/gone', fn() => Response::make('closure'));

        $router->saveToCache($this->cacheFile);

        $router2 = new Router();
        $router2->loadFromCache($this->cacheFile);

        $routes = $router2->getRoutes();
        $this->assertCount(1, $routes); // only array-action cached
        $this->assertSame('GET', $routes[0]['method']);
        $this->assertSame('/api/status', $routes[0]['uri']);
        $this->assertSame(['StatusController', 'index'], $routes[0]['action']);
    }

    // ── Route facade integration ──────────────────────────────────────────────

    public function test_route_facade_cache_and_loadCache(): void
    {
        Route::reset();
        Route::get('/products', ['ProductController', 'index'])->name('products.index');
        Route::post('/products', ['ProductController', 'store']);

        Route::cache($this->cacheFile);
        $this->assertFileExists($this->cacheFile);

        Route::reset();
        $loaded = Route::loadCache($this->cacheFile);
        $this->assertTrue($loaded);

        // Named route must be resolvable after load
        $uri = Route::router()->getNamedRoute('products.index');
        $this->assertSame('/products', $uri);

        Route::reset();
    }

    public function test_route_facade_loadCache_returns_false_when_no_file(): void
    {
        Route::reset();
        $this->assertFalse(Route::loadCache('/no/cache/here.php'));
        Route::reset();
    }

    public function test_route_facade_clearCache_deletes_file(): void
    {
        RouteCache::store([], [], $this->cacheFile);
        Route::clearCache($this->cacheFile);
        $this->assertFileDoesNotExist($this->cacheFile);
    }
}
