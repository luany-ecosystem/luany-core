# luany-core

> HTTP Request/Response, middleware pipeline and router for the Luany ecosystem.

The runtime foundation of the Luany framework — controls the full HTTP lifecycle from request to response.

## Request → Resolution → Execution → Response

```
Request::fromGlobals()
    └─ Router::dispatch()
        └─ Pipeline (middleware chain)
            └─ Controller action
                └─ Response::send()
```

## Installation

```bash
composer require luany/core
```

## Router

```php
use Luany\Core\Routing\Route;

Route::get('/', [HomeController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);
Route::put('/users/{id}', [UserController::class, 'update']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);

// Named routes
Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show');
$uri = Route::router()->getNamedRoute('users.show', ['id' => 42]); // /users/42

// Groups
Route::prefix('admin')->middleware(MyCustomMiddleware::class)->group(function () {
    Route::get('/dashboard', [AdminController::class, 'index']);
    Route::get('/users',     [AdminController::class, 'users']);
});

// Resource routes (RESTful CRUD)
Route::resource('posts', PostController::class);
Route::apiResource('posts', PostController::class); // without create/edit

// View routes
Route::setViewRenderer(fn($view, $data) => $engine->render($view, $data));
Route::view('/welcome', 'pages.welcome');

// Handle (returns Response — preferred for testability)
$response = Route::handle();
$response->send();

// Or dispatch (convenience wrapper)
Route::dispatch();
```

## Request

```php
use Luany\Core\Http\Request;

public function store(Request $request): Response
{
    $name  = $request->input('name');
    $email = $request->post('email');
    $page  = $request->query('page', 1);

    $data = $request->only(['name', 'email']);
    $data = $request->except(['_token']);
    $all  = $request->all();

    $request->has('name');       // bool
    $request->filled('name');    // bool — false if empty string
    $request->isPost();          // bool
    $request->isAjax();          // bool
    $request->expectsJson();     // bool
    $request->ip();              // string
}

// Create from globals (called automatically by Router::dispatch)
$request = Request::fromGlobals();
```

## Response

```php
use Luany\Core\Http\Response;

return Response::make('<h1>Hello</h1>');
return Response::make('<h1>Created</h1>', 201);
return Response::json(['status' => 'ok']);
return Response::json(['error' => 'Not found'], 404);
return Response::redirect('/dashboard');
return Response::redirect('/new-url', 301);
return Response::notFound();
return Response::unauthorized();
return Response::forbidden();
return Response::serverError();

return (new Response())
    ->status(200)
    ->header('X-Custom', 'value')
    ->body('<p>Content</p>');
```

Controllers can return `Response`, `string`, or `array` — the router normalises automatically.

## Middleware

Implement `MiddlewareInterface` to create middleware:

```php
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Core\Http\Request;
use Luany\Core\Http\Response;

class MyCustomMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Before action
        $response = $next($request);
        // After action
        return $response;
    }
}
```

Short-circuit (stop the pipeline):

```php
class MyCustomMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (/* condition */) {
            return Response::redirect('/login');
        }
        return $next($request);
    }
}
```

### Pipeline

```php
use Luany\Core\Middleware\Pipeline;

$response = (new Pipeline())
    ->send($request)
    ->through([MyCustomMiddleware::class])
    ->then(fn(Request $req) => $controller->action($req));

$response->send();
```

## Requirements

- PHP 8.1+

## Testing

```bash
composer install
vendor/bin/phpunit
```

77 tests, 100 assertions.

## Changelog

### v0.2.1
- README corrected — middleware examples updated to generic `MyCustomMiddleware`; authentication middleware moved to `luany-framework`

### v0.2.0
- `Request` — full HTTP request encapsulation (`fromGlobals`, JSON body, method override, `input`, `only`, `except`, `has`, `filled`, `isAjax`, `expectsJson`)
- `Response` — status, headers, body, `json()`, `redirect()`, error factories, `send()`
- `MiddlewareInterface` — contract `handle(Request, callable): Response`
- `Pipeline` — middleware chain with correct wrapping order and short-circuit support
- `Router` — dispatch cycle uses `Request`/`Response`/`Pipeline`; `handle()` returns Response, `dispatch()` sends
- `Route::view()` — injectable renderer via `Route::setViewRenderer()`, no external helpers
- Fixed: `RouteGroup` and `RouteRegistrar` namespace (`Core\Routing` → `Luany\Core\Routing`)
- 77 unit tests — `RequestTest`, `ResponseTest`, `RouterTest`, `PipelineTest`

### v0.1.0
- Initial release — `Router`, `RouteGroup`, `RouteRegistrar`, `Route` facade
- Resource routes, named routes, group prefix/middleware

## License

MIT — see [LICENSE](LICENSE) for details.