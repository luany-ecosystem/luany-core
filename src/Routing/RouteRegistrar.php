<?php

namespace Core\Routing;

/**
 * RouteRegistrar
 *
 * Returned by Router::addRoute() to allow fluent chaining:
 *
 *   Route::get('/users', [UserController::class, 'index'])
 *       ->name('users.index')
 *       ->middleware(AuthMiddleware::class);
 *
 * Both $route and $namedRoutes are held by reference so that any
 * mutation here is immediately visible to the Router instance.
 */
class RouteRegistrar
{
    /** @var array Reference to the route entry inside Router::$routes */
    private array $route;

    /** @var array Reference to Router::$namedRoutes */
    private array $namedRoutes;

    public function __construct(array &$route, array &$namedRoutes)
    {
        $this->route       = &$route;
        $this->namedRoutes = &$namedRoutes;
    }

    /**
     * Assign a name to this route AND register it in the named-routes index.
     */
    public function name(string $name): self
    {
        $this->route['name']       = $name;
        $this->namedRoutes[$name]  = $this->route['uri'];  // ← Key fix
        return $this;
    }

    /**
     * Add one or more middleware to this route.
     */
    public function middleware($middleware): self
    {
        $list = is_array($middleware) ? $middleware : [$middleware];
        foreach ($list as $m) {
            $this->route['middleware'][] = $m;
        }
        return $this;
    }
}
