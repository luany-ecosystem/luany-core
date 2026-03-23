<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;

class CorsMiddlewareTest extends TestCase
{
    private function makeRequest(string $method = 'GET', string $origin = ''): Request
    {
        $headers = $origin !== '' ? ['Origin' => $origin] : [];
        return new Request($method, '/api/test', [], [], [], $headers);
    }

    private function next(): callable
    {
        return fn(Request $req) => Response::make('ok', 200);
    }

    // ── Preflight (OPTIONS) ────────────────────────────────────────────────────

    public function test_preflight_returns_204(): void
    {
        $cors     = new CorsMiddleware();
        $request  = $this->makeRequest('OPTIONS', 'https://app.example.com');
        $response = $cors->handle($request, $this->next());

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_preflight_short_circuits_pipeline(): void
    {
        $called = false;
        $cors   = new CorsMiddleware();
        $req    = $this->makeRequest('OPTIONS', 'https://example.com');

        $cors->handle($req, function () use (&$called) {
            $called = true;
            return Response::make('should not reach');
        });

        $this->assertFalse($called);
    }

    public function test_preflight_has_allow_methods_header(): void
    {
        $cors     = new CorsMiddleware(allowedMethods: ['GET', 'POST']);
        $response = $cors->handle($this->makeRequest('OPTIONS', 'https://x.com'), $this->next());

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Methods', $headers);
        $this->assertStringContainsString('GET', $headers['Access-Control-Allow-Methods']);
        $this->assertStringContainsString('POST', $headers['Access-Control-Allow-Methods']);
    }

    // ── Wildcard origin ────────────────────────────────────────────────────────

    public function test_wildcard_origin_sets_star(): void
    {
        $cors     = new CorsMiddleware(allowedOrigins: ['*']);
        $response = $cors->handle($this->makeRequest('GET', 'https://anyone.com'), $this->next());

        $this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);
    }

    public function test_wildcard_with_credentials_echoes_actual_origin(): void
    {
        $cors     = new CorsMiddleware(allowedOrigins: ['*'], allowCredentials: true);
        $response = $cors->handle($this->makeRequest('GET', 'https://myapp.com'), $this->next());

        $headers = $response->getHeaders();
        $this->assertSame('https://myapp.com', $headers['Access-Control-Allow-Origin']);
        $this->assertSame('true', $headers['Access-Control-Allow-Credentials']);
    }

    // ── Specific origins ──────────────────────────────────────────────────────

    public function test_allowed_specific_origin_is_echoed_back(): void
    {
        $cors     = new CorsMiddleware(allowedOrigins: ['https://app.luany.dev']);
        $response = $cors->handle($this->makeRequest('GET', 'https://app.luany.dev'), $this->next());

        $this->assertSame('https://app.luany.dev', $response->getHeaders()['Access-Control-Allow-Origin']);
    }

    public function test_disallowed_origin_gets_no_cors_headers(): void
    {
        $cors     = new CorsMiddleware(allowedOrigins: ['https://trusted.com']);
        $response = $cors->handle($this->makeRequest('GET', 'https://evil.com'), $this->next());

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->getHeaders());
    }

    public function test_wildcard_subdomain_pattern_matches(): void
    {
        $cors     = new CorsMiddleware(allowedOrigins: ['*.luany.dev']);
        $response = $cors->handle($this->makeRequest('GET', 'https://api.luany.dev'), $this->next());

        $this->assertSame('https://api.luany.dev', $response->getHeaders()['Access-Control-Allow-Origin']);
    }

    public function test_wildcard_subdomain_does_not_match_unrelated_domain(): void
    {
        $cors     = new CorsMiddleware(allowedOrigins: ['*.luany.dev']);
        $response = $cors->handle($this->makeRequest('GET', 'https://evil.com'), $this->next());

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->getHeaders());
    }

    // ── Credentials ───────────────────────────────────────────────────────────

    public function test_credentials_header_set_when_allowed(): void
    {
        $cors     = new CorsMiddleware(
            allowedOrigins:   ['https://app.com'],
            allowCredentials: true
        );
        $response = $cors->handle($this->makeRequest('GET', 'https://app.com'), $this->next());

        $this->assertSame('true', $response->getHeaders()['Access-Control-Allow-Credentials']);
    }

    public function test_credentials_header_absent_when_not_allowed(): void
    {
        $cors     = new CorsMiddleware(allowCredentials: false);
        $response = $cors->handle($this->makeRequest('GET', 'https://anything.com'), $this->next());

        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->getHeaders());
    }

    // ── Exposed headers ───────────────────────────────────────────────────────

    public function test_exposed_headers_are_added_when_configured(): void
    {
        $cors     = new CorsMiddleware(exposedHeaders: ['X-Total-Count', 'X-Page']);
        $response = $cors->handle($this->makeRequest('GET', 'https://x.com'), $this->next());

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Access-Control-Expose-Headers', $headers);
        $this->assertStringContainsString('X-Total-Count', $headers['Access-Control-Expose-Headers']);
    }

    public function test_exposed_headers_absent_when_not_configured(): void
    {
        $cors     = new CorsMiddleware();
        $response = $cors->handle($this->makeRequest('GET', 'https://x.com'), $this->next());

        $this->assertArrayNotHasKey('Access-Control-Expose-Headers', $response->getHeaders());
    }

    // ── Max age ───────────────────────────────────────────────────────────────

    public function test_max_age_header_is_set(): void
    {
        $cors     = new CorsMiddleware(maxAge: 3600);
        $response = $cors->handle($this->makeRequest('OPTIONS', 'https://x.com'), $this->next());

        $this->assertSame('3600', $response->getHeaders()['Access-Control-Max-Age']);
    }

    // ── Actual request passthrough ────────────────────────────────────────────

    public function test_actual_request_body_is_preserved(): void
    {
        $cors     = new CorsMiddleware();
        $response = $cors->handle($this->makeRequest('GET', 'https://x.com'), $this->next());

        $this->assertSame('ok', $response->getBody());
        $this->assertSame(200, $response->getStatusCode());
    }

    // ── No origin header ─────────────────────────────────────────────────────

    public function test_request_without_origin_header_still_gets_cors_headers_for_wildcard(): void
    {
        $cors     = new CorsMiddleware(allowedOrigins: ['*']);
        $response = $cors->handle($this->makeRequest('GET', ''), $this->next());

        // Wildcard allows all, including no-origin requests
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $response->getHeaders());
    }
}
