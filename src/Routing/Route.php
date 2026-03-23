<?php

namespace Luany\Core\Routing;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;

/**
 * Route Facade
 *
 * Static API over the singleton Router instance.
 * All calls delegate to the underlying Router.
 *
 * Usage:
 *   Route::get('/users', [UserController::class, 'index']);
 *   Route::post('/users', [UserController::class, 'store']);
 *   Route::resource('users', UserController::class);
 *
 * Model binding:
 *   Route::bind('user', fn($id) => User::find($id));
 *   Route::model('post', Post::class);   // shorthand for find()
 *
 * Route caching:
 *   Route::loadCache(base_path('storage/cache/routes.php'))
 *       || require base_path('routes/http.php');
 *   Route::cache(base_path('storage/cache/routes.php'));
 */
class Route
{
    private static ?Router $router = null;

    /**
     * Get the singleton Router instance.
     */
    public static function router(): Router
    {
        if (self::$router === null) {
            self::$router = new Router();
        }
        return self::$router;
    }

    /**
     * Alias for router() — returns the singleton Router instance.
     *
     * Provided for readability in application code:
     *   Route::getRouter()->getNamedRoute('users.index')
     */
    public static function getRouter(): Router
    {
        return self::router();
    }

    /**
     * Get all registered routes from the singleton Router.
     *
     * Returns the raw routes array — each entry has:
     *   'method', 'uri', 'action', 'middleware', 'name'
     *
     * Used by luany route:list to display registered routes.
     *
     * @return array<int, array{method: string, uri: string, action: mixed, middleware: array, name: ?string}>
     */
    /** @return array<int, array<string, mixed>> */
    public static function getRoutes(): array
    {
        return self::router()->getRoutes();
    }

    /**
     * Replace the singleton Router (useful for testing).
     */
    public static function setRouter(Router $router): void
    {
        self::$router = $router;
    }

    /**
     * Reset the singleton Router to null.
     * Call in test setUp() to prevent route bleed between tests.
     */
    public static function reset(): void
    {
        self::$router      = null;
        self::$viewRenderer = null;
    }

    // ── HTTP verb registration ────────────────────────────────────────────────

    public static function get(string $uri, mixed $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('GET', $uri, $action, $name);
    }

    public static function post(string $uri, mixed $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('POST', $uri, $action, $name);
    }

    public static function put(string $uri, mixed $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('PUT', $uri, $action, $name);
    }

    public static function delete(string $uri, mixed $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('DELETE', $uri, $action, $name);
    }

    public static function patch(string $uri, mixed $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('PATCH', $uri, $action, $name);
    }

    public static function any(string $uri, mixed $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('ANY', $uri, $action, $name);
    }

    // ── Resource routing ──────────────────────────────────────────────────────

    /**
     * Register resource routes (RESTful CRUD).
     *
     * Generates:
     *   GET    /resource           → index
     *   GET    /resource/create    → create
     *   POST   /resource           → store
     *   GET    /resource/{id}      → show
     *   GET    /resource/{id}/edit → edit
     *   PUT    /resource/{id}      → update
     *   PATCH  /resource/{id}      → update (alternative)
     *   DELETE /resource/{id}      → destroy
     *
     * Options:
     *   ['only'   => ['index', 'show']]      — include only these actions
     *   ['except' => ['create', 'edit']]     — exclude these actions
     */
    /** @param array<string, mixed> $options */
    public static function resource(string $name, string $controller, array $options = []): void
    {
        $base = '/' . trim($name, '/');

        $actions = [
            'index'   => ['GET',    $base,               'index'],
            'create'  => ['GET',    "{$base}/create",    'create'],
            'store'   => ['POST',   $base,               'store'],
            'show'    => ['GET',    "{$base}/{id}",      'show'],
            'edit'    => ['GET',    "{$base}/{id}/edit", 'edit'],
            'update'  => ['PUT',    "{$base}/{id}",      'update'],
            'destroy' => ['DELETE', "{$base}/{id}",      'destroy'],
        ];

        $only   = is_array($options['only']   ?? null) ? $options['only']   : array_keys($actions);
        $except = is_array($options['except'] ?? null) ? $options['except'] : [];

        foreach ($only as $action) {
            if (in_array($action, $except, true) || !isset($actions[$action])) {
                continue;
            }
            [$method, $uri, $methodName] = $actions[$action];
            self::{strtolower($method)}($uri, [$controller, $methodName])
                ->name("{$name}.{$action}");
        }

        // Also register PATCH for update
        if (in_array('update', $only, true) && !in_array('update', $except, true)) {
            self::patch("{$base}/{id}", [$controller, 'update']);
        }
    }

    /**
     * Register API resource routes (no create/edit form routes).
     *
     * Generates: index, store, show, update, destroy.
     */
    /** @param array<string, mixed> $options */
    public static function apiResource(string $name, string $controller, array $options = []): void
    {
        $options['except'] = array_merge($options['except'] ?? [], ['create', 'edit']);
        self::resource($name, $controller, $options);
    }

    // ── Group routing ─────────────────────────────────────────────────────────

    public static function middleware(mixed $middleware): RouteGroup
    {
        return (new RouteGroup(self::router()))->middleware($middleware);
    }

    public static function prefix(string $prefix): RouteGroup
    {
        return (new RouteGroup(self::router()))->prefix($prefix);
    }

    /**
     * Shorthand for combining prefix + middleware in one call.
     *
     * Usage:
     *   Route::group(['prefix' => '/admin', 'middleware' => [AuthMiddleware::class]], function () {
     *       Route::get('/dashboard', [AdminController::class, 'dashboard']);
     *   });
     */
    /** @param array<string, mixed> $attributes */
    public static function group(array $attributes, callable $callback): void
    {
        $group = new RouteGroup(self::router());

        if (!empty($attributes['prefix'])) {
            $group->prefix($attributes['prefix']);
        }
        if (!empty($attributes['middleware'])) {
            $group->middleware($attributes['middleware']);
        }

        $group->group($callback);
    }

    // ── View routes ───────────────────────────────────────────────────────────

    private static ?\Closure $viewRenderer = null;

    public static function setViewRenderer(\Closure $renderer): void
    {
        self::$viewRenderer = $renderer;
    }

    /**
     * Register a view-only route (no controller).
     *
     * Requires Route::setViewRenderer() to be called at bootstrap.
     */
    public static function view(
        string $uri,
        string $viewName,
        /** @param array<string, mixed> $data */
        array $data = [],
        ?string $name = null
    ): RouteRegistrar {
        $renderer = self::$viewRenderer;

        $action = function ($request) use ($viewName, $data, $renderer) {
            $viewData = array_merge($data, $_GET);

            if ($renderer !== null) {
                return ($renderer)($viewName, $viewData);
            }

            if (function_exists('view')) {
                return view($viewName, $viewData);
            }

            throw new \RuntimeException(
                'No view renderer configured. Call Route::setViewRenderer() at bootstrap.'
            );
        };

        return self::get($uri, $action, $name);
    }

    // ── Model binding ─────────────────────────────────────────────────────────

    /**
     * Register a custom route parameter resolver.
     *
     * When a route parameter named $param is encountered, its raw string value
     * is replaced with the return value of $resolver before the action is called.
     *
     * Usage:
     *   Route::bind('user', fn($id) => User::find($id));
     *
     *   Route::get('/users/{user}', function (Request $req, ?User $user) {
     *       // $user is already a User instance (or null if not found)
     *   });
     *
     * @param string   $param    Route parameter name (without braces)
     * @param callable $resolver Receives the raw string value, returns the resolved value
     */
    public static function bind(string $param, callable $resolver): void
    {
        self::router()->bind($param, $resolver);
    }

    /**
     * Register a model-resolving binding using the model's find() method.
     *
     * Shorthand for: Route::bind($param, fn($id) => $modelClass::find($id))
     *
     * Usage:
     *   Route::model('user', User::class);
     *   Route::get('/users/{user}', [UserController::class, 'show']);
     *   // Controller receives a ?User instance instead of raw ID string
     *
     * @param string $param      Route parameter name (without braces)
     * @param string $modelClass FQCN of the model class (must have a static find() method)
     */
    public static function model(string $param, string $modelClass): void
    {
        self::router()->bind($param, fn($id) => $modelClass::find($id));
    }

    // ── Route caching ─────────────────────────────────────────────────────────

    /**
     * Save the current route table to a cache file.
     *
     * Only array-action routes are cached (closure routes are excluded).
     * Call this once after all routes have been registered — typically in
     * a `luany route:cache` CLI command (Phase 6).
     *
     * @param string $path Absolute path to the cache file
     */
    public static function cache(string $path): void
    {
        self::router()->saveToCache($path);
    }

    /**
     * Load the route table from a cache file.
     *
     * Returns true if the cache was loaded; false if the file does not exist.
     * On false, the caller must fall back to executing routes/http.php normally.
     *
     * Usage in production bootstrap:
     *   if (!Route::loadCache(base_path('storage/cache/routes.php'))) {
     *       require base_path('routes/http.php');
     *       Route::cache(base_path('storage/cache/routes.php'));
     *   }
     *
     * @param string $path Absolute path to the cache file
     */
    public static function loadCache(string $path): bool
    {
        return self::router()->loadFromCache($path);
    }

    /**
     * Delete the route cache file.
     *
     * @param string $path Absolute path to the cache file
     */
    public static function clearCache(string $path): void
    {
        RouteCache::clear($path);
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    /**
     * Resolve the request to a Response — does NOT send.
     * Preferred over dispatch() for testability.
     */
    public static function handle(?Request $request = null): Response
    {
        return self::router()->handle($request);
    }

    /**
     * Resolve and send immediately.
     */
    public static function dispatch(): void
    {
        self::router()->dispatch();
    }
}
