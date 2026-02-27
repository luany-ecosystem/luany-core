<?php

namespace Luany\Core\Routing;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;

/**
 * Route Facade - Luany framework
 * 
 * Usage:
 * Route::get('/users', [UserController::class, 'index']);
 * Route::post('/users', [UserController::class, 'store']);
 * Route::resource('users', UserController::class);
 */
class Route
{
    private static ?Router $router = null;
    
    /**
     * Get router instance (Singleton)
     */
    private static function router(): Router
    {
        if (self::$router === null) {
            self::$router = new Router();
        }
        return self::$router;
    }
    
    /**
     * Register GET route
     */
    public static function get(string $uri, $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('GET', $uri, $action, $name);
    }
    
    /**
     * Register POST route
     */
    public static function post(string $uri, $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('POST', $uri, $action, $name);
    }
    
    /**
     * Register PUT route
     */
    public static function put(string $uri, $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('PUT', $uri, $action, $name);
    }
    
    /**
     * Register DELETE route
     */
    public static function delete(string $uri, $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('DELETE', $uri, $action, $name);
    }
    
    /**
     * Register PATCH route
     */
    public static function patch(string $uri, $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('PATCH', $uri, $action, $name);
    }
    
    /**
     * Register ANY route (all methods)
     */
    public static function any(string $uri, $action, ?string $name = null): RouteRegistrar
    {
        return self::router()->addRoute('ANY', $uri, $action, $name);
    }
    
    /**
     * Register resource routes (RESTful CRUD)
     * 
     * Generates:
     * GET    /resource           -> index
     * GET    /resource/create    -> create
     * POST   /resource           -> store
     * GET    /resource/{id}      -> show
     * GET    /resource/{id}/edit -> edit
     * PUT    /resource/{id}      -> update
     * PATCH  /resource/{id}      -> update (alternative)
     * DELETE /resource/{id}      -> destroy
     * 
     * Usage:
     * Route::resource('users', UserController::class);
     * Route::resource('users', UserController::class, ['only' => ['index', 'show']]);
     * Route::resource('users', UserController::class, ['except' => ['create', 'edit']]);
     */
    public static function resource(string $name, string $controller, array $options = []): void
    {
        $base = '/' . trim($name, '/');
        
        // Define all available actions
        $actions = [
            'index' => ['GET', $base, 'index'],
            'create' => ['GET', "{$base}/create", 'create'],
            'store' => ['POST', $base, 'store'],
            'show' => ['GET', "{$base}/{id}", 'show'],
            'edit' => ['GET', "{$base}/{id}/edit", 'edit'],
            'update' => ['PUT', "{$base}/{id}", 'update'],
            'destroy' => ['DELETE', "{$base}/{id}", 'destroy'],
        ];
        
        // Determine which actions to register
        $only = $options['only'] ?? array_keys($actions);
        $except = $options['except'] ?? [];
        
        if (!is_array($only)) {
            $only = array_keys($actions);
        }
        if (!is_array($except)) {
            $except = [];
        }
        
        foreach ($only as $action) {
            if (in_array($action, $except) || !isset($actions[$action])) {
                continue;
            }
            
            [$method, $uri, $methodName] = $actions[$action];
            
            // Register route using appropriate method
            $methodFunc = strtolower($method);
            self::{$methodFunc}($uri, [$controller, $methodName])
                ->name("{$name}.{$action}");
        }
        
        // Also register PATCH for update if update was registered
        if (in_array('update', $only) && !in_array('update', $except)) {
            self::patch("{$base}/{id}", [$controller, 'update']);
        }
    }
    
    /**
     * Register API resource routes (RESTful CRUD for APIs)
     * Like resource() but without create/edit forms (suitable for APIs)
     * 
     * Generates:
     * GET    /resource       -> index
     * POST   /resource       -> store
     * GET    /resource/{id}  -> show
     * PUT    /resource/{id}  -> update
     * PATCH  /resource/{id}  -> update (alternative)
     * DELETE /resource/{id}  -> destroy
     * 
     * Usage:
     * Route::apiResource('posts', PostController::class);
     * Route::apiResource('posts', PostController::class, ['only' => ['index', 'show']]);
     */
    public static function apiResource(string $name, string $controller, array $options = []): void
    {
        // API resources don't have 'create' and 'edit' form routes
        $options['except'] = array_merge(
            $options['except'] ?? [],
            ['create', 'edit']
        );
        
        self::resource($name, $controller, $options);
    }
    
    /**
     * Register middleware group
     */
    public static function middleware($middleware): RouteGroup
    {
        $group = new RouteGroup(self::router());
        return $group->middleware($middleware);
    }
    
    /**
     * Register route prefix group
     */
    public static function prefix(string $prefix): RouteGroup
    {
        $group = new RouteGroup(self::router());
        return $group->prefix($prefix);
    }
    
    /**
     * Register view route (returns view without controller)
     * 
     * Usage:
     * Route::view('/welcome', 'welcome');
     * Route::view('/welcome', 'welcome', ['name' => 'Taylor']);
     */
    /**
     * Register a view route.
     * The $renderer callable receives (string $viewName, array $data): string.
     * Inject your engine at bootstrap via Route::setViewRenderer().
     *
     * Usage (in bootstrap/app.php):
     *   Route::setViewRenderer(fn($view, $data) => $engine->render($view, $data));
     *   Route::view('/welcome', 'pages.welcome');
     */
    private static ?\Closure $viewRenderer = null;

    public static function setViewRenderer(\Closure $renderer): void
    {
        self::$viewRenderer = $renderer;
    }

    public static function view(
        string $uri,
        string $viewName,
        array $data = [],
        ?string $name = null
    ): RouteRegistrar {
        $renderer = self::$viewRenderer;

        $action = function ($request) use ($viewName, $data, $renderer) {
            $viewData = array_merge($data, $_GET);

            if ($renderer !== null) {
                return ($renderer)($viewName, $viewData);
            }

            // Fallback: look for a registered view() function
            if (function_exists('view')) {
                return view($viewName, $viewData);
            }

            throw new \RuntimeException(
                "No view renderer configured. Call Route::setViewRenderer() at bootstrap."
            );
        };

        return self::get($uri, $action, $name);
    }
    
    /**
     * Resolve the request and return a Response — does NOT send.
     * Preferred over dispatch() for testability.
     *
     * Usage (public/index.php):
     *   Route::handle()->send();
     */
    public static function handle(?Request $request = null): Response
    {
        return self::router()->handle($request);
    }

    /**
     * Resolve and send immediately.
     * Convenience wrapper for handle()->send().
     */
    public static function dispatch(): void
    {
        self::router()->dispatch();
    }
}