# Changelog — luany/core

All notable changes to this package are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2026-03-23

### Added
- `Request::body(): array` — returns parsed request body fields only (POST or decoded JSON). Distinct from `all()` which merges body + query string. Used by `validate()` helper and all generated controllers.
- `Route::getRouter(): Router` — alias for `router()`. Used by application `route()` helper to resolve named routes.
- `Route::getRoutes(): array` — static facade over `Router::getRoutes()`. Used by the `route:list` CLI command to display registered routes.

### Fixed
- Missing `use Luany\Core\Routing\Route` import in `RouterTest` caused `Class not found` error when running `test_get_router_alias_returns_same_instance` and `test_get_routes_returns_all_registered_routes`.

---

## [0.2.4] — Phase 4

### Added
- `MethodNotAllowedException` — thrown by `Router` when a URI matches but the HTTP method does not. Distinct from `RouteNotFoundException` (404). Carries `getAllowedMethods()` and `getAllowHeaderValue()` per RFC 7231 §6.5.5.
- `CorsMiddleware` — handles CORS preflight (OPTIONS → 204) and actual requests. Supports wildcard `*`, specific origin allowlists, wildcard subdomains `*.example.com`, `allowCredentials`, `exposedHeaders`, `maxAge`.
- `RateLimitMiddleware` — applies rate limiting via a pluggable `RateLimiterInterface`. Returns 429 with `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` headers.
- `RateLimiterInterface` — contract for `attempt()`, `remaining()`, `availableAt()`, `tooManyAttempts()`, `reset()`.
- `InMemoryRateLimiter` — static in-process store. Suitable for testing and development.
- `FileRateLimiter` — JSON file-backed store with `flock()` concurrency safety. Suitable for single-server production.
- `RouteCache` — serialize the route table to a PHP file (`store()`) and reload it (`load()`), bypassing `routes/http.php` on every request in production.
- `Route::bind(string $param, callable $resolver)` — register a custom route parameter resolver (model binding).
- `Route::model(string $param, string $modelClass)` — shorthand binding via `$modelClass::find($id)`.
- `Route::cache()` / `Route::loadCache()` / `Route::clearCache()` — route caching API.
- `Route::apiResource()` — resource routes without `create` and `edit` form routes.
- `Route::group(array $attributes, callable $callback)` — combine `prefix` and `middleware` in one call.
- `RouteGroup` — fluent `prefix()` + `middleware()` + `group()` chaining.
- `RouteRegistrar` — returned by `addRoute()`, enables `.name()` and `.middleware()` fluent chaining on individual routes.

---

## [0.2.3]

### Fixed
- Route parameter values no longer pollute the `$_GET` superglobal. Extracted params are passed directly to the action without modifying PHP globals.

---

## [0.2.2]

### Added
- `Request::cookie(string $key, mixed $default): mixed` — read a cookie value by name.
- `Request::hasCookie(string $key): bool` — check if a cookie is present.
- `Request::$cookies` — populated in `fromGlobals()` from `$_COOKIE`.

### Fixed
- Cookies were not assigned in the `Request` constructor, causing `cookie()` to always return the default.

---

## [0.2.1]

### Added
- `RouteNotFoundException` — `Router::handle()` now throws this exception instead of returning a 404 `Response` directly. Allows the application `Handler` to render a styled 404 view.

---

## [0.2.0] — Initial implementation

### Added
- `Request` — `fromGlobals()`, `method()`, `uri()`, `input()`, `query()`, `post()`, `all()`, `only()`, `except()`, `has()`, `filled()`, `file()`, `hasFile()`, `header()`, `headers()`, `server()`, `ip()`, `userAgent()`, `url()`, `isAjax()`, `expectsJson()`. HTTP method override via hidden `_method` field. JSON body auto-parsing.
- `Response` — `make()`, `json()`, `redirect()`, `notFound()`, `unauthorized()`, `forbidden()`, `serverError()`, fluent `status()`, `body()`, `header()`, `withHeaders()`, `send()`.
- `Pipeline` — `send()` / `through()` / `then()` middleware chain with `array_reduce` composition.
- `MiddlewareInterface` — `handle(Request $request, callable $next): Response`.
- `Route` facade — `get/post/put/delete/patch/any`, `resource()`, `view()`, `middleware()`, `prefix()`, `handle()`, `dispatch()`, `reset()`, `setViewRenderer()`.
- `Router` — two-pass URI matching (404 vs 405), named routes, group context stack.

### Removed
- `AuthMiddleware` — removed from core. Core is pure HTTP infrastructure; authentication middleware belongs in the application layer.