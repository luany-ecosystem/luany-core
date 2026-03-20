<?php

namespace Luany\Core\Routing;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\Pipeline;
use Luany\Core\Exceptions\MethodNotAllowedException;
use Luany\Core\Exceptions\RouteNotFoundException;

/**
 * Router Engine
 *
 * Handles route registration, group context (prefix + middleware),
 * named route resolution, request dispatching, model binding, and caching.
 *
 * Dispatch cycle:
 *   Request → two-pass match → resolve bindings → Pipeline (middleware) → action → Response
 *
 * Two-pass matching:
 *   Pass 1: URI + method match → dispatch immediately
 *   Pass 2: URI matches but method doesn't → throw MethodNotAllowedException (405)
 *   No URI match at all → throw RouteNotFoundException (404)
 *
 * Model binding:
 *   Route::bind('user', fn($id) => User::find($id));
 *   Route::get('/users/{user}', [UserController::class, 'show']);
 *   // $user param is resolved to a User instance before action is called
 *
 * Route caching:
 *   $router->saveToCache('/path/to/routes.cache.php');
 *   $router->loadFromCache('/path/to/routes.cache.php');
 *   // Caches array-action routes; closure routes are excluded (not serializable)
 */
class Router
{
    /** @var array<int, array<string, mixed>> */
    private array $routes      = [];
    /** @var array<string, string> */
    private array $namedRoutes = [];
    /** @var array<int, array<string, mixed>> */
    private array $groupStack  = [];

    /** @var array<string, callable(string): mixed> Param → resolver mapping for model binding */
    private array $bindings = [];

    // ── Group context ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    public function pushGroupContext(array $context): void
    {
        $this->groupStack[] = $context;
    }

    public function popGroupContext(): void
    {
        array_pop($this->groupStack);
    }

    private function getCurrentPrefix(): string
    {
        $prefix = '';
        foreach ($this->groupStack as $frame) {
            if (!empty($frame['prefix'])) {
                $prefix .= '/' . trim($frame['prefix'], '/');
            }
        }
        return $prefix;
    }

    /** @return array<int, class-string|object> */
    private function getCurrentMiddleware(): array
    {
        $middleware = [];
        foreach ($this->groupStack as $frame) {
            if (!empty($frame['middleware'])) {
                $middleware = array_merge($middleware, (array) $frame['middleware']);
            }
        }
        return $middleware;
    }

    // ── Route registration ─────────────────────────────────────────────────────

    public function addRoute(string $method, string $uri, mixed $action, ?string $name = null): RouteRegistrar
    {
        $prefix = $this->getCurrentPrefix();
        $uri    = $prefix . '/' . ltrim($uri, '/');
        $uri    = '/' . trim($uri, '/');
        if ($uri === '') $uri = '/'; // @phpstan-ignore identical.alwaysFalse

        $middleware = $this->getCurrentMiddleware();

        $route = [
            'method'     => strtoupper($method),
            'uri'        => $uri,
            'action'     => $action,
            'middleware' => $middleware,
            'name'       => $name,
        ];

        $index = count($this->routes);
        $this->routes[$index] = $route;

        if ($name !== null) {
            $this->namedRoutes[$name] = $uri;
        }

        return new RouteRegistrar($this->routes[$index], $this->namedRoutes);
    }

    // ── Named routes ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    public function getNamedRoute(string $name, array $params = []): ?string
    {
        if (!isset($this->namedRoutes[$name])) {
            return null;
        }

        $uri = $this->namedRoutes[$name];

        foreach ($params as $key => $value) {
            $uri = str_replace('{' . $key . '}', $value, $uri);
        }

        return $uri;
    }

    // ── Model binding ──────────────────────────────────────────────────────────

    /**
     * Register a route parameter resolver.
     *
     * Any route parameter named $param will have its raw string value replaced
     * with the return value of $resolver before the action is called.
     *
     * Usage:
     *   $router->bind('user', fn($id) => User::find($id));
     *   // Route: GET /users/{user}
     *   // Action receives a User instance (or null) instead of raw ID string
     *
     * @param string   $param    Route parameter name (without braces)
     * @param callable $resolver Callable that receives the raw string value
     */
    public function bind(string $param, callable $resolver): void
    {
        $this->bindings[$param] = $resolver;
    }

    /**
     * Get all registered bindings (used by RouteCache).
     *
     * @return array<string, callable>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    // ── Route caching ──────────────────────────────────────────────────────────

    /**
     * Serialize the current route table to a PHP file.
     *
     * Only routes with array actions ([Controller::class, 'method']) are cached.
     * Closure actions cannot be serialized and are silently excluded.
     *
     * The cache file can be loaded with loadFromCache() to skip re-executing
     * routes/http.php on every request in production.
     *
     * @param string $path Absolute path to the cache file (e.g. storage/cache/routes.php)
     * @throws \RuntimeException If the file cannot be written
     */
    public function saveToCache(string $path): void
    {
        RouteCache::store($this->routes, $this->namedRoutes, $path);
    }

    /**
     * Load a previously cached route table.
     *
     * Returns true if the cache was loaded successfully, false if the cache
     * file does not exist (caller should fall back to loading routes normally).
     *
     * @param string $path Absolute path to the cache file
     */
    public function loadFromCache(string $path): bool
    {
        $data = RouteCache::load($path);

        if ($data === null) {
            return false;
        }

        $this->routes      = $data['routes'];
        $this->namedRoutes = $data['named'];

        return true;
    }

    /**
     * Return the raw routes array (used by RouteCache and tests).
     */
    /** @return array<int, array<string, mixed>> */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Return the named routes index (used by RouteCache and tests).
     */
    /** @return array<string, string> */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    // ── Dispatch ───────────────────────────────────────────────────────────────

    /**
     * Resolve the request to a Response — does NOT send.
     *
     * Two-pass matching algorithm:
     *   1. For each route: check URI pattern match, THEN check method.
     *      → First full match (URI + method) → dispatch immediately.
     *      → URI match but wrong method → collect allowed method.
     *   2. After loop:
     *      → Collected allowed methods → throw MethodNotAllowedException (405)
     *      → No URI match at all → throw RouteNotFoundException (404)
     *
     * This correctly distinguishes "route not found" (404) from
     * "route found but wrong method" (405), per RFC 7231 §6.5.5.
     */
    public function handle(?Request $request = null): Response
    {
        $request ??= Request::fromGlobals();

        $method         = $request->method();
        $uri            = $request->uri();
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $pattern = $this->compilePattern($route['uri']);

            if (!preg_match($pattern, $uri, $matches)) {
                continue; // URI does not match — skip
            }

            // URI matches — check method
            if ($route['method'] === $method || $route['method'] === 'ANY') {
                $params = $this->resolveBindings(
                    $this->extractParams($matches)
                );

                return (new Pipeline())
                    ->send($request)
                    ->through($route['middleware'])
                    ->then(fn(Request $req) => $this->executeAction($route['action'], $req, $params));
            }

            // URI matches but method does not — record for potential 405
            if ((string) $route['method'] !== 'ANY') {
                $allowedMethods[] = $route['method'];
            }
        }

        // URI was found but method is not supported → 405
        if (!empty($allowedMethods)) {
            throw new MethodNotAllowedException($method, $uri, array_unique($allowedMethods));
        }

        // No route matched at all → 404
        throw new RouteNotFoundException($method, $uri);
    }

    /**
     * Resolve and send immediately.
     * Convenience wrapper for handle()->send().
     */
    public function dispatch(?Request $request = null): void
    {
        $this->handle($request)->send();
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function compilePattern(string $uri): string
    {
        $pattern = str_replace('/', '\\/', $uri);
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^\\/]+)', $pattern);
        return '#^' . $pattern . '$#';
    }

    /**
     * @param array<int|string, string> $matches
     * @return array<string, string>
     */
    private function extractParams(array $matches): array
    {
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    /**
     * Apply registered model bindings to the extracted route parameters.
     * Any param without a registered binding passes through unchanged.
     *
     * @param  array<string, string>  $params  Raw string params from URI
     * @return array<string, mixed>            Params after binding resolution
     */
    private function resolveBindings(array $params): array
    {
        $resolved = [];
        foreach ($params as $key => $value) {
            $resolved[$key] = isset($this->bindings[$key])
                ? ($this->bindings[$key])($value)
                : $value;
        }
        return $resolved;
    }

    /** @param array<string, mixed> $params */
    private function executeAction(mixed $action, Request $request, array $params = []): Response
    {
        if (is_callable($action)) {
            $result = call_user_func($action, $request, ...array_values($params));
            return $this->toResponse($result);
        }

        if (is_array($action)) {
            [$controller, $method] = $action;

            if (is_string($controller) && !class_exists($controller)) {
                $fqn = '\\App\\Http\\Controllers\\' . $controller;
                if (class_exists($fqn)) {
                    $controller = $fqn;
                } else {
                    throw new \RuntimeException("Controller not found: {$controller}");
                }
            }

            $instance = is_string($controller) ? new $controller() : $controller;

            if (!method_exists($instance, $method)) {
                throw new \RuntimeException("Method [{$method}] not found in [" . get_class($instance) . "]");
            }

            $result = call_user_func([$instance, $method], $request, ...array_values($params));
            return $this->toResponse($result);
        }

        throw new \RuntimeException('Invalid route action — must be callable or [Controller::class, method]');
    }

    /**
     * Coerce a controller return value into a Response.
     * Accepts: Response | string | array (auto JSON)
     */
    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }

        return Response::make((string) ($result ?? ''));
    }
}
