<?php

namespace Luany\Core\Tests;

use Luany\Core\Exceptions\MethodNotAllowedException;
use Luany\Core\Exceptions\RouteNotFoundException;
use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

class MethodNotAllowedTest extends TestCase
{
    private function makeRequest(string $method, string $uri): Request
    {
        return new Request($method, $uri);
    }

    // ── Exception class ───────────────────────────────────────────────────────

    public function test_exception_has_405_code(): void
    {
        $e = new MethodNotAllowedException('DELETE', '/users', ['GET', 'POST']);
        $this->assertSame(405, $e->getCode());
    }

    public function test_exception_message_contains_method_and_uri(): void
    {
        $e = new MethodNotAllowedException('DELETE', '/users', ['GET', 'POST']);
        $this->assertStringContainsString('DELETE', $e->getMessage());
        $this->assertStringContainsString('/users', $e->getMessage());
    }

    public function test_exception_getAllowedMethods_returns_uppercase(): void
    {
        $e = new MethodNotAllowedException('DELETE', '/users', ['get', 'post']);
        $this->assertSame(['GET', 'POST'], $e->getAllowedMethods());
    }

    public function test_exception_getAllowHeaderValue(): void
    {
        $e = new MethodNotAllowedException('DELETE', '/users', ['GET', 'POST']);
        $this->assertSame('GET, POST', $e->getAllowHeaderValue());
    }

    // ── Router throws 405 when URI matches but method doesn't ─────────────────

    public function test_router_throws_405_for_wrong_method(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users', fn() => Response::make('ok'));

        $this->expectException(MethodNotAllowedException::class);
        $router->handle($this->makeRequest('DELETE', '/users'));
    }

    public function test_router_405_exception_has_correct_code(): void
    {
        $router = new Router();
        $router->addRoute('POST', '/login', fn() => Response::make('ok'));

        try {
            $router->handle($this->makeRequest('GET', '/login'));
            $this->fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(405, $e->getCode());
        }
    }

    public function test_router_405_allowed_methods_reflect_registered_routes(): void
    {
        $router = new Router();
        $router->addRoute('GET',  '/articles', fn() => Response::make('ok'));
        $router->addRoute('POST', '/articles', fn() => Response::make('ok'));

        try {
            $router->handle($this->makeRequest('DELETE', '/articles'));
            $this->fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            $allowed = $e->getAllowedMethods();
            $this->assertContains('GET',  $allowed);
            $this->assertContains('POST', $allowed);
            $this->assertNotContains('DELETE', $allowed);
        }
    }

    public function test_router_404_when_uri_does_not_match_at_all(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users', fn() => Response::make('ok'));

        $this->expectException(RouteNotFoundException::class);
        $router->handle($this->makeRequest('GET', '/nonexistent'));
    }

    public function test_router_404_has_404_code(): void
    {
        $router = new Router();

        try {
            $router->handle($this->makeRequest('GET', '/nothing'));
            $this->fail('Expected RouteNotFoundException');
        } catch (RouteNotFoundException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function test_router_does_not_throw_405_for_uri_mismatch(): void
    {
        // /users/42 does NOT match /posts/{id} — should 404, not 405
        $router = new Router();
        $router->addRoute('DELETE', '/posts/{id}', fn() => Response::make('ok'));

        $this->expectException(RouteNotFoundException::class);
        $router->handle($this->makeRequest('GET', '/users/42'));
    }

    public function test_router_any_method_route_never_triggers_405(): void
    {
        $router = new Router();
        $router->addRoute('ANY', '/ping', fn() => Response::make('pong'));

        // ANY should match every method — no 405
        $response = $router->handle($this->makeRequest('DELETE', '/ping'));
        $this->assertSame('pong', $response->getBody());
    }

    public function test_405_allowed_methods_are_deduplicated(): void
    {
        $router = new Router();
        // Two routes for same URI+method — both would appear in allowed list
        $router->addRoute('GET', '/items', fn() => Response::make('a'));
        $router->addRoute('GET', '/items', fn() => Response::make('b'));

        try {
            $router->handle($this->makeRequest('POST', '/items'));
        } catch (MethodNotAllowedException $e) {
            // Should have GET only once, not twice
            $this->assertCount(1, $e->getAllowedMethods());
        }
    }
}
