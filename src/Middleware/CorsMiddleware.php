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
    ) {
        if ($allowCredentials && in_array('*', $allowedOrigins, true)) {
            throw new \InvalidArgumentException(
                'CORS: allowCredentials cannot be combined with the "*" origin — '
                . 'that would let any website make authenticated requests. List the allowed origins explicitly.'
            );
        }
    }

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

        // The response depends on Origin whenever we don't answer "*":
        // tell shared caches so one origin's response is not served to another.
        if ($allowedOrigin !== '*') {
            $response = $response->header('Vary', $this->appendVary($response, 'Origin'));
        }

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
        // Wildcard — allow all origins (never combined with credentials, see constructor)
        if (in_array('*', $this->allowedOrigins, true)) {
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
    /**
     * Supported patterns:
     *   https://app.example.com    exact origin
     *   https://*.example.com      any subdomain, HTTPS only
     *   *.example.com              any subdomain, any scheme
     */
    private function originMatches(string $pattern, string $origin): bool
    {
        if (strcasecmp($pattern, $origin) === 0) {
            return true;
        }

        if (!str_contains($pattern, '*.')) {
            return false;
        }

        $originScheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        $originHost   = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $originPort   = parse_url($origin, PHP_URL_PORT);

        if ($originHost === '') {
            return false;
        }

        $patternScheme = null;
        $hostPattern   = $pattern;

        if (str_contains($pattern, '://')) {
            [$patternScheme, $hostPattern] = explode('://', $pattern, 2);
            $patternScheme = strtolower($patternScheme);
        }

        $patternPort = null;
        if (preg_match('/^(.*):(\d+)$/', $hostPattern, $m) === 1) {
            [$hostPattern, $patternPort] = [$m[1], (int) $m[2]];
        }

        if ($patternScheme !== null && $patternScheme !== $originScheme) {
            return false;
        }
        if ($patternPort !== $originPort) {
            return false;
        }

        $domain = strtolower(substr($hostPattern, 2)); // strip "*."

        return str_ends_with($originHost, '.' . $domain);
    }

    private function appendVary(Response $response, string $header): string
    {
        $existing = '';
        foreach ($response->getHeaders() as $name => $value) {
            if (strcasecmp($name, 'Vary') === 0) {
                $existing = $value;
            }
        }

        $values = array_filter(array_map('trim', explode(',', $existing)));
        foreach ($values as $v) {
            if (strcasecmp($v, $header) === 0) {
                return implode(', ', $values);
            }
        }

        $values[] = $header;
        return implode(', ', $values);
    }
}
