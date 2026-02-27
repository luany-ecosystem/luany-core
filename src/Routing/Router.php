<?php

namespace Luany\Core\Routing;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\Pipeline;

/**
 * Router Engine
 *
 * Handles route registration, group context (prefix + middleware),
 * named route resolution and request dispatching.
 *
 * Dispatch cycle:
 *   Request → match route → run Pipeline (middleware) → execute action → Response::send()
 *
 * Group context stack
 * ───────────────────
 * When Route::prefix() or Route::middleware() opens a group,
 * it pushes a context frame onto $groupStack.
 * Every call to addRoute() reads the stack to apply prefix + middleware.
 * When the group callback finishes, the frame is popped.
 */
class Router
{
    private array $routes      = [];
    private array $namedRoutes = [];
    private array $groupStack  = [];

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

    public function addRoute(string $method, string $uri, mixed $action, ?string $name = null): RouteRegistrar
    {
        $prefix = $this->getCurrentPrefix();
        $uri    = $prefix . '/' . ltrim($uri, '/');
        $uri    = '/' . trim($uri, '/');
        if ($uri === '') $uri = '/';

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

    /**
     * Resolve the request to a Response — does NOT send.
     * Call ->send() on the returned Response at the application entry point.
     *
     * Usage (public/index.php):
     *   Route::handle()->send();
     */
    public function handle(?Request $request = null): Response
    {
        $request ??= Request::fromGlobals();

        $method = $request->method();
        $uri    = $request->uri();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            $pattern = $this->compilePattern($route['uri']);

            if (preg_match($pattern, $uri, $matches)) {
                $params = $this->extractParams($matches);

                foreach ($params as $key => $value) {
                    $_GET[$key] = $value;
                }

                return (new Pipeline())
                    ->send($request)
                    ->through($route['middleware'])
                    ->then(fn(Request $req) => $this->executeAction($route['action'], $req, $params));
            }
        }

        return $this->notFoundResponse();
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

        if (is_string($result) || is_null($result)) {
            return Response::make((string) $result);
        }

        return Response::make((string) $result);
    }

    private function notFoundResponse(): Response
    {
        $body = '<h1>404 — Page Not Found</h1>';

        if (defined('ERRORS_PATH') && file_exists(ERRORS_PATH . '/404.php')) {
            ob_start();
            require ERRORS_PATH . '/404.php';
            $body = ob_get_clean();
        }

        return Response::notFound($body);
    }
}