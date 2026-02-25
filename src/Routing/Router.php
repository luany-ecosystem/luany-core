<?php

namespace Luany\Core\Routing;

/**
 * Router Engine
 *
 * Handles route registration, group context (prefix + middleware),
 * named route resolution and request dispatching.
 *
 * Group context stack
 * ───────────────────
 * When Route::prefix() or Route::middleware() opens a group,
 * it pushes a context frame onto $groupStack.
 * Every call to addRoute() reads the stack to apply prefix + middleware.
 * When the group callback finishes, the frame is popped.
 *
 * This means group callbacks can use Route::get() etc. directly — the
 * context is read transparently without needing to change how routes are declared.
 */
class Router
{
    private array  $routes      = [];
    private array  $namedRoutes = [];

    /** Stack of [{prefix, middleware}] — pushed/popped by RouteGroup::group() */
    private array  $groupStack  = [];

    // ── Group context ──────────────────────────────────────────────────────────

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

    /**
     * Register a route and return a RouteRegistrar for fluent chaining.
     * Automatically applies current group prefix and middleware.
     */
    public function addRoute(string $method, string $uri, $action, ?string $name = null): RouteRegistrar
    {
        // Apply group prefix
        $prefix = $this->getCurrentPrefix();
        $uri    = $prefix . '/' . ltrim($uri, '/');
        $uri    = '/' . trim($uri, '/');
        if ($uri === '/') $uri = '/';

        // Apply group middleware
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

        // Pass references so RouteRegistrar mutations are reflected here
        return new RouteRegistrar($this->routes[$index], $this->namedRoutes);
    }

    // ── Named routes ──────────────────────────────────────────────────────────

    /**
     * Resolve a named route to its URI, replacing {param} placeholders.
     */
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

    // ── Dispatch ───────────────────────────────────────────────────────────────

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Strip script directory from URI (e.g. when running in a sub-folder)
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        if ($scriptDir && $scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        $uri = '/' . trim($uri, '/');
        if ($uri === '/') $uri = '/';

        // Method override via _method in POST body
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper($_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            $pattern = $this->compilePattern($route['uri']);

            if (preg_match($pattern, $uri, $matches)) {
                $params = $this->extractParams($matches);

                // Run route middleware
                foreach ($route['middleware'] as $middleware) {
                    $this->runMiddleware($middleware);
                }

                $this->executeAction($route['action'], $params);
                return;
            }
        }

        $this->handleNotFound();
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function compilePattern(string $uri): string
    {
        $pattern = str_replace('/', '\\/', $uri);
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^\\/]+)', $pattern);
        return '#^' . $pattern . '$#';
    }

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

    private function runMiddleware($middleware): void
    {
        if (is_callable($middleware)) {
            $middleware();
            return;
        }

        if (is_string($middleware) && class_exists($middleware)) {
            $instance = new $middleware();
            if (method_exists($instance, 'handle')) {
                $instance->handle();
            }
        }
    }

    private function executeAction($action, array $params = []): void
    {
        if (is_callable($action)) {
            $result = call_user_func_array($action, array_values($params));
            if (is_string($result)) echo $result;
            return;
        }

        if (is_array($action)) {
            [$controller, $method] = $action;

            if (!class_exists($controller)) {
                $controller = '\\App\\Http\\Controllers\\' . $controller;
            }

            if (!class_exists($controller)) {
                throw new \Exception("Controller not found: {$controller}");
            }

            $instance = new $controller();

            if (!method_exists($instance, $method)) {
                throw new \Exception("Method [{$method}] not found in [{$controller}]");
            }

            // Inject route params into $_GET for backward compatibility
            foreach ($params as $key => $value) {
                $_GET[$key] = $value;
            }

            $result = call_user_func_array([$instance, $method], array_values($params));
            if (is_string($result)) echo $result;
            return;
        }

        throw new \Exception('Invalid route action');
    }

    private function handleNotFound(): void
    {
        http_response_code(404);

        if (defined('ERRORS_PATH') && file_exists(ERRORS_PATH . '/404.php')) {
            require ERRORS_PATH . '/404.php';
        } else {
            echo '<h1>404 — Page Not Found</h1>';
        }

        exit;
    }
}