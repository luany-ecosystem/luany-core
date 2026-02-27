<?php

namespace Luany\Core\Routing;

/**
 * RouteGroup
 *
 * Allows grouping routes under shared prefix and/or middleware.
 *
 * Usage:
 *   Route::middleware(AuthMiddleware::class)->group(function () {
 *       Route::get('/dashboard', [DashboardController::class, 'index']);
 *   });
 *
 *   Route::prefix('admin')->middleware(AuthMiddleware::class)->group(function () {
 *       Route::get('/users', [AdminController::class, 'users']); // → /admin/users
 *   });
 *
 * How it works
 * ────────────
 * group() pushes a context frame onto Router's groupStack before calling
 * the callback, then pops it after. While the frame is active, every
 * Route::get/post/… call automatically inherits the prefix and middleware
 * declared here — without needing to pass the groupRouter as argument.
 */
class RouteGroup
{
    private Router $router;
    private array  $middleware = [];
    private string $prefix     = '';

    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    public function middleware($middleware): self
    {
        $this->middleware = is_array($middleware) ? $middleware : [$middleware];
        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = trim($prefix, '/');
        return $this;
    }

    /**
     * Execute the callback with prefix + middleware active.
     * The callback uses Route:: static methods as normal —
     * the context is injected transparently via the groupStack.
     */
    public function group(callable $callback): void
    {
        $this->router->pushGroupContext([
            'prefix'     => $this->prefix,
            'middleware' => $this->middleware,
        ]);

        try {
            $callback();
        } finally {
            $this->router->popGroupContext();
        }
    }
}