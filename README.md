# luany/core

**HTTP Request/Response, Router, Middleware Pipeline, CORS, Rate Limiting, and Route Caching for the Luany ecosystem.**

**Version**: v1.0.0 &nbsp;|&nbsp; **PHP**: >= 8.2 &nbsp;|&nbsp; **License**: MIT
**Author**: António Ambrósio Ngola &nbsp;|&nbsp; **Org**: [luany-ecosystem](https://github.com/luany-ecosystem)

---

## Table of Contents

1. [Installation](#1-installation)
2. [Request](#2-request)
3. [Response](#3-response)
4. [Router & Route Facade](#4-router--route-facade)
5. [Middleware](#5-middleware)
6. [Rate Limiters](#6-rate-limiters)
7. [Exceptions](#7-exceptions)
8. [Changelog](#8-changelog)

---

## 1. Installation

```bash
composer require luany/core
```

---

## 2. Request

**Class**: `Luany\Core\Http\Request`

```php
$request = Request::fromGlobals();

$request->method();                          // 'GET'
$request->uri();                             // '/users/42'
$request->input('name', 'default');          // body or query value
$request->query('page');                     // query string only
$request->post('email');                     // body only
$request->all();                             // merged query + body$request->body();                            // body only$request->only(['name', 'email']);
$request->except(['password']);
$request->has('name');                       // bool
$request->filled('name');                    // bool — exists and non-empty
$request->file('avatar');                    // ?array
$request->hasFile('avatar');                 // bool
$request->header('Authorization');           // case-insensitive
$request->server('REMOTE_ADDR');
$request->cookie('app_locale', 'en');
$request->hasCookie('app_locale');
$request->ip();                              // REMOTE_ADDR (X-Forwarded-For only via trusted proxies)
$request->isSecure();                        // HTTPS (X-Forwarded-Proto only via trusted proxies)

// Behind a load balancer / reverse proxy:
Request::setTrustedProxies(['10.0.0.0/8', '127.0.0.1']);
$request->userAgent();
$request->url();                             // full URL with scheme+host
$request->isGet(); $request->isPost();       // method shortcuts
$request->isMethod('DELETE');
$request->isAjax();
$request->expectsJson();
```

**Method override**: HTML forms may include a hidden `_method` field (`PUT`, `PATCH`, `DELETE`). `fromGlobals()` handles this automatically.

**JSON body**: If `Content-Type: application/json`, the body is parsed from `php://input` automatically.

**Body-only helper**: `Request::body()` returns only parsed body fields (POST/JSON), unlike `all()` which merges body + query.

---

## 3. Response

**Class**: `Luany\Core\Http\Response`

```php
// Factories
Response::make('<h1>Hello</h1>', 200);
Response::json(['user' => $user]);
Response::json(['error' => 'Not found'], 404);
Response::redirect('/dashboard');
Response::redirect('/permanent', 301);
Response::notFound();
Response::unauthorized();
Response::forbidden();
Response::serverError();

// Fluent building
(new Response())
    ->status(422)
    ->body(json_encode(['errors' => $errors]))
    ->header('Content-Type', 'application/json')
    ->withHeaders(['X-Request-Id' => $id]);

// Inspection
$response->getStatusCode();  // int
$response->getBody();        // string
$response->getHeaders();     // array
$response->isRedirect();     // bool (3xx)
$response->isSuccessful();   // bool (2xx)

// Send to client (call once at end of lifecycle)
$response->send();
```

---

## 4. Router & Route Facade

**Classes**: `Luany\Core\Routing\Router`, `Luany\Core\Routing\Route`

`Route` is a static facade over the singleton `Router` instance.

### Basic Registration

```php
use Luany\Core\Routing\Route;

Route::get('/users',       [UserController::class, 'index']);
Route::post('/users',      [UserController::class, 'store']);
Route::put('/users/{id}',  [UserController::class, 'update']);
Route::patch('/users/{id}',[UserController::class, 'update']);
Route::delete('/users/{id}',[UserController::class, 'destroy']);
Route::any('/ping', fn() => Response::make('pong'));

// Closure actions
Route::get('/hello', function (Request $request) {
    return Response::make('Hello!');
});
```

Controllers may return `Response`, `string`, or `array` (auto-JSON).

### Route Parameters

```php
Route::get('/users/{id}', [UserController::class, 'show']);
// Controller: public function show(Request $request, string $id): Response

Route::get('/posts/{post}/comments/{comment}', [CommentController::class, 'show']);
// Parameters passed in order of appearance. Never written to $_GET.
```

### Named Routes

```php
Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show');

$url = Route::router()->getNamedRoute('users.show', ['id' => 42]);
// → '/users/42'
```

### Route Groups

```php
// Prefix
Route::prefix('/admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']); // → /admin/dashboard
});

// Middleware
Route::middleware(AuthMiddleware::class)->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
});

// Combined shorthand
Route::group(['prefix' => '/api/v1', 'middleware' => [AuthMiddleware::class]], function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
});

// Nested
Route::prefix('/api')->group(function () {
    Route::prefix('/v1')->group(function () {
        Route::get('/status', fn() => Response::json(['ok' => true]));
        // → /api/v1/status
    });
});
```

### Resource Routes

```php
Route::resource('products', ProductController::class);
// GET    /products           → index
// GET    /products/create    → create
// POST   /products           → store
// GET    /products/{id}      → show
// GET    /products/{id}/edit → edit
// PUT    /products/{id}      → update
// PATCH  /products/{id}      → update
// DELETE /products/{id}      → destroy

Route::apiResource('posts', PostController::class);
// Same but without create/edit

Route::resource('users', UserController::class, ['only' => ['index', 'show']]);
Route::resource('users', UserController::class, ['except' => ['create', 'edit']]);
```

### View Routes

```php
Route::setViewRenderer(fn($view, $data) => $engine->render($view, $data));

Route::view('/welcome', 'pages.welcome');
Route::view('/about', 'pages.about', ['title' => 'About Us']);
```

### Model Binding

Automatically resolve route parameters to model instances before the action is called.

```php
// Custom resolver
Route::bind('user', fn($id) => User::find($id));

// Shorthand — uses the model's static find() method
Route::model('post', Post::class);

// Action receives a resolved instance (or null if not found)
Route::get('/users/{user}', function (Request $request, ?User $user) {
    if ($user === null) {
        return Response::notFound();
    }
    return Response::json($user->toArray());
});
```

Unbound parameters pass through as raw strings. Bindings match by parameter name.

### Dependency injection

Controllers and class-name middleware are created through a resolver. Register your container once:

```php
use Luany\Core\Middleware\Pipeline;

Pipeline::setDefaultResolver(fn (string $class) => $container->make($class));
// or per router:
Route::router()->setResolver(fn (string $class) => $container->make($class));
```

Without a resolver, `new $class()` is used.

Action arguments can be resolved the same way. An invoker receives the action, the request and the route parameters by name:

```php
Route::router()->setInvoker(
    fn (callable $action, Request $request, array $params) => $container->call($action, $request, $params)
);
```

Without an invoker, actions receive `($request, ...$params)` in order. luany/framework registers one automatically.

### PSR interoperability (PSR-7 / PSR-15 / PSR-11)

Requires a PSR-7/PSR-17 implementation; `nyholm/psr7` and `guzzlehttp/psr7` are auto-detected:

```bash
composer require nyholm/psr7
```

**PSR-15 middleware** from any package works in routes and groups, as an instance or class name:

```php
Route::get('/api/data', [ApiController::class, 'index'])
    ->middleware(new \Middlewares\Cors($analyzer));       // any Psr\Http\Server\MiddlewareInterface
```

**Run the app behind a PSR-15 server** (RoadRunner, FrankenPHP worker mode, ReactPHP, …):

```php
use Luany\Core\Psr\RequestHandler;

$handler = new RequestHandler(fn (Request $request) => $kernel->handle($request));
$psrResponse = $handler->handle($psrServerRequest);
```

**Convert messages** when calling PSR libraries directly:

```php
$psrRequest = $request->toPsr();            // ServerRequestInterface
$request    = Request::fromPsr($psrRequest);
$psrResponse = $response->toPsr();          // ResponseInterface
$response    = Response::fromPsr($psrResponse);
```

**Any PSR-11 container** can build middleware and controllers:

```php
Pipeline::useContainer($container);   // Psr\Container\ContainerInterface
```

Use another PSR-17 factory explicitly with `Luany\Core\Psr\Psr17::use($factory)`.

### Route Caching

Serializes the compiled route table to a PHP file (written atomically). Array actions (`[Controller::class, 'method']`), `Route::view()` routes and `Route::model()` bindings are cached. Closure actions, closure `Route::bind()` resolvers and object middleware without `__set_state()` cannot be serialized: `Route::cache()` **throws and lists the offending routes** instead of silently dropping them.

```php
// In production bootstrap:
if (!Route::loadCache(base_path('storage/cache/routes.php'))) {
    require base_path('routes/http.php');
    Route::cache(base_path('storage/cache/routes.php'));
}

// Invalidate after deployment:
Route::clearCache(base_path('storage/cache/routes.php'));
```

---

## 5. Middleware

### Pipeline

```php
use Luany\Core\Middleware\Pipeline;

$response = (new Pipeline())
    ->send($request)
    ->through([AuthMiddleware::class, LogMiddleware::class])
    ->then(fn(Request $req) => $controller->action($req));
```

Implementing middleware:

```php
use Luany\Core\Middleware\MiddlewareInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!isset($_SESSION['user_id'])) {
            return Response::redirect('/login');
        }
        return $next($request);
    }
}
```

### CorsMiddleware

```php
use Luany\Core\Middleware\CorsMiddleware;

// Default — allow all origins (public API, no credentials)
$cors = new CorsMiddleware();

// Production — specific origins with credentials
$cors = new CorsMiddleware(
    allowedOrigins:   ['https://app.example.com', '*.staging.example.com'],
    allowedMethods:   ['GET', 'POST', 'PUT', 'DELETE'],
    allowedHeaders:   ['Content-Type', 'Authorization'],
    exposedHeaders:   ['X-Total-Count'],
    allowCredentials: true,
    maxAge:           3600,
);
```

Behaviour: `OPTIONS` requests short-circuit with 204. Wildcard `['*']` combined with `allowCredentials: true` is **rejected** (it would let any site make authenticated requests). Reflected origins add `Vary: Origin`. Disallowed origins get no CORS headers. Subdomain patterns: `https://*.example.com` (scheme-checked) or `*.example.com` (any scheme).

### RateLimitMiddleware

```php
use Luany\Core\Middleware\RateLimitMiddleware;
use Luany\Core\RateLimit\FileRateLimiter;

$limiter = new FileRateLimiter(base_path('storage/rate-limits'));

// On a route
Route::post('/login', [AuthController::class, 'login'])
    ->middleware(new RateLimitMiddleware($limiter, maxAttempts: 5, decaySeconds: 60));
```

Exceeding the limit returns `429` with `X-RateLimit-Limit`, `X-RateLimit-Remaining: 0`, and `Retry-After` headers. Allowed requests receive `X-RateLimit-Limit` and `X-RateLimit-Remaining`.

Override `keyFor(Request $request): string` to key by user ID instead of IP.

---

## 6. Rate Limiters

### InMemoryRateLimiter

Per-process static store. For tests and development only.

```php
use Luany\Core\RateLimit\InMemoryRateLimiter;

$limiter = new InMemoryRateLimiter();
$limiter->attempt('key', 5, 60);           // bool
$limiter->remaining('key', 5);             // int
$limiter->tooManyAttempts('key', 5);       // bool
$limiter->availableAt('key');              // int (Unix timestamp)
$limiter->reset('key');                    // void
InMemoryRateLimiter::flush();             // clear all keys (use in test tearDown)
```

### FileRateLimiter

JSON file-backed store. Safe for single-server production. Uses `flock()` for concurrency safety. Keys are SHA-256 hashed — no path traversal possible.

```php
use Luany\Core\RateLimit\FileRateLimiter;

$limiter = new FileRateLimiter(base_path('storage/rate-limits'));
$limiter->attempt('api:ip:127.0.0.1', 60, 60);
$limiter->reset('api:ip:127.0.0.1');
$limiter->flush(); // removes all rl_*.json files
```

### Custom RateLimiter

Implement `Luany\Core\RateLimit\RateLimiterInterface`:

```php
interface RateLimiterInterface
{
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool;
    public function remaining(string $key, int $maxAttempts): int;
    public function availableAt(string $key): int;
    public function tooManyAttempts(string $key, int $maxAttempts): bool;
    public function reset(string $key): void;
}
```

---

## 7. Exceptions

| Exception                   | Code | When thrown                                      |
| --------------------------- | ---- | ------------------------------------------------ |
| `RouteNotFoundException`    | 404  | No route URI matches the request                 |
| `MethodNotAllowedException` | 405  | URI matches a route but the HTTP method does not |

```php
use Luany\Core\Exceptions\MethodNotAllowedException;
use Luany\Core\Exceptions\RouteNotFoundException;

// In your application's Exception Handler:
public function render(\Throwable $e): Response
{
    if ($e instanceof MethodNotAllowedException) {
        return Response::make('Method Not Allowed', 405)
            ->header('Allow', $e->getAllowHeaderValue());
    }
    if ($e instanceof RouteNotFoundException) {
        return Response::notFound();
    }
    return parent::render($e);
}
```

`MethodNotAllowedException::getAllowedMethods(): string[]` — e.g. `['GET', 'POST']`
`MethodNotAllowedException::getAllowHeaderValue(): string` — e.g. `'GET, POST'`

---

## 8. Changelog

### v1.0.0 — Phase 4: Core Hardening

**New — `src/Routing/RouteCache.php`**

- `store()` — serialize route table to PHP file (closure routes excluded)
- `load()` — load cached route table
- `clear()` — delete cache file

**Modified — `src/Routing/Router.php`**

- Two-pass dispatch: URI match + wrong method → `MethodNotAllowedException` (405) instead of 404
- `bind(string $param, callable $resolver)` — register route model binding
- `getBindings()`, `getRoutes()`, `getNamedRoutes()` — expose state for cache and testing
- `saveToCache()` / `loadFromCache()` — route cache integration

**Modified — `src/Routing/Route.php`**

- `bind()`, `model()` — model binding API
- `cache()`, `loadCache()`, `clearCache()` — cache API
- `group(array $attributes, callable $callback)` — combined prefix+middleware shorthand
- `setRouter()`, `reset()` — testing helpers

**Existing (shipped before Phase 4, now fully tested):**
`MethodNotAllowedException`, `CorsMiddleware`, `RateLimitMiddleware`,
`RateLimiterInterface`, `InMemoryRateLimiter`, `FileRateLimiter`

**Tests added:** `MethodNotAllowedTest` (12), `CorsMiddlewareTest` (16), `RateLimiterTest` (15), `FileRateLimiterTest` (14), `RateLimitMiddlewareTest` (9), `RouteCacheTest` (15), `RouteModelBindingTest` (10)

**Total: OK (180 tests, 261 assertions)**

---

### v0.2.4 and earlier

HTTP Request/Response, Router with groups and named routes, resource/apiResource, Pipeline, RouteRegistrar, RouteGroup, method override, `$_GET` isolation.
