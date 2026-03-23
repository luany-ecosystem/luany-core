<?php

namespace Luany\Core\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;

/**
 * CorsMiddleware
 *
 * Handles Cross-Origin Resource Sharing (CORS) headers.
 *
 * Responsibilities:
 *   1. Preflight requests (OPTIONS): respond immediately with 204 + CORS headers.
 *      This short-circuits the pipeline — no route action is executed.
 *   2. Actual requests: add CORS headers to the response from the next handler.
 *
 * Usage — register globally in app/Http/Kernel.php:
 *   protected array $middleware = [
 *       CorsMiddleware::class,
 *       // ...
 *   ];
 *
 * Usage — configure with custom options (via a subclass or DI):
 *   $cors = new CorsMiddleware(
 *       allowedOrigins:     ['https://app.example.com'],
 *       allowedMethods:     ['GET', 'POST', 'PUT', 'DELETE'],
 *       allowedHeaders:     ['Content-Type', 'Authorization'],
 *       allowCredentials:   true,
 *       maxAge:             3600,
 *   );
 *
 * Default configuration allows all origins ('*') without credentials — safe for
 * public APIs. Tighten to specific origins for production apps that use cookies
 * or Authorization headers.
 *
 * NOTE: Wildcard origin ('*') is incompatible with allowCredentials=true.
 * The middleware enforces this: if credentials are allowed, a concrete matching
 * origin is echoed back instead of '*'.
 */
class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param string[] $allowedOrigins  Origins allowed to make cross-origin requests.
     *                                  Use ['*'] to allow all origins (default).
     *                                  Use specific origins for credentialed requests.
     * @param string[] $allowedMethods  HTTP methods allowed for cross-origin requests.
     * @param string[] $allowedHeaders  Headers the browser may send in a cross-origin request.
     * @param string[] $exposedHeaders  Headers the browser is allowed to access in the response.
     * @param bool     $allowCredentials  Whether cookies / auth headers are allowed.
     * @param int      $maxAge          How long (in seconds) the preflight result can be cached.
     */
    public function __construct(
        private readonly array $allowedOrigins   = ['*'],
        private readonly array $allowedMethods   = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private readonly array $allowedHeaders   = ['Content-Type', 'Authorization', 'X-Requested-With'],
        private readonly array $exposedHeaders   = [],
        private readonly bool  $allowCredentials = false,
        private readonly int   $maxAge           = 86400,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        // Preflight request — respond immediately, do NOT continue pipeline
        if ($request->isMethod('OPTIONS')) {
            return $this->buildPreflightResponse($request);
        }

        // Actual request — let it through, then add CORS headers to the response
        $response = $next($request);

        return $this->addCorsHeaders($response, $request->header('Origin', ''));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build the 204 No Content response for OPTIONS preflight requests.
     */
    private function buildPreflightResponse(Request $request): Response
    {
        $response = Response::make('', 204);
        return $this->addCorsHeaders($response, $request->header('Origin', ''));
    }

    /**
     * Add all applicable CORS headers to a Response.
     */
    private function addCorsHeaders(Response $response, string $requestOrigin): Response
    {
        $allowedOrigin = $this->resolveAllowedOrigin($requestOrigin);

        if ($allowedOrigin === null) {
            // Origin not allowed — return response without CORS headers
            return $response;
        }

        $response = $response
            ->header('Access-Control-Allow-Origin',  $allowedOrigin)
            ->header('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->header('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->header('Access-Control-Max-Age',       (string) $this->maxAge);

        if ($this->allowCredentials) {
            $response = $response->header('Access-Control-Allow-Credentials', 'true');
        }

        if (!empty($this->exposedHeaders)) {
            $response = $response->header(
                'Access-Control-Expose-Headers',
                implode(', ', $this->exposedHeaders)
            );
        }

        return $response;
    }

    /**
     * Resolve the Access-Control-Allow-Origin value for the given request origin.
     *
     * Rules:
     *   - Wildcard ['*'] with no credentials → return '*'
     *   - Wildcard ['*'] with credentials → echo back the request origin (required by spec)
     *   - Specific origins list → return origin if it matches, null if not
     *   - Empty request origin → return first allowed origin or '*'
     *
     * Returns null if the origin is not allowed (no CORS headers should be added).
     */
    private function resolveAllowedOrigin(string $requestOrigin): ?string
    {
        // Wildcard — allow all origins
        if ($this->allowedOrigins === ['*']) {
            // Credentials + wildcard: must reflect the actual origin (spec requirement)
            if ($this->allowCredentials && $requestOrigin !== '') {
                return $requestOrigin;
            }
            return '*';
        }

        // No origin in request (same-origin or non-browser client) — still add headers
        if ($requestOrigin === '') {
            return $this->allowedOrigins[0] ?? null;
        }

        // Check if the request origin is in the allowlist
        foreach ($this->allowedOrigins as $allowed) {
            if ($this->originMatches($allowed, $requestOrigin)) {
                return $requestOrigin; // Echo back the actual origin, not the pattern
            }
        }

        return null; // Origin not allowed
    }

    /**
     * Match a configured origin against the request origin.
     * Supports exact strings and simple wildcard subdomains: '*.example.com'
     */
    private function originMatches(string $pattern, string $origin): bool
    {
        if ($pattern === $origin) {
            return true;
        }

        // Wildcard subdomain pattern: *.example.com
        if (str_starts_with($pattern, '*.')) {
            $domain = substr($pattern, 2); // strip '*.'
            // Match: any-subdomain.example.com or example.com itself
            return str_ends_with($origin, '.' . $domain)
                || $origin === $domain;
        }

        return false;
    }
}
