# Luany Core

> Router, middleware pipeline and DI resolver hook for the Luany ecosystem.

## Installation

```bash
composer require luany/core
```

## Routing

```php
use Luany\Core\Routing\Route;
use App\Http\Controllers\HomeController;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::post('/users', [UserController::class, 'store']);
Route::resource('posts', PostController::class);
```

### Groups & Middleware

```php
use Luany\Core\Middleware\AuthMiddleware;

Route::middleware(AuthMiddleware::class)->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

Route::prefix('api')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
});
```

### Named Routes

```php
Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show');
```

## DI Container Hook

```php
// Plug any PSR-11 container
Route::getRouter()->setResolver(fn($class) => $container->get($class));
```

## Requirements

- PHP 8.1+

## License

MIT — see [LICENSE](LICENSE) for details.
