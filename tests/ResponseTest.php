<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    // ── Constructor & make ────────────────────────────────────────────────────

    public function test_default_status_is_200(): void
    {
        $this->assertSame(200, (new Response())->getStatusCode());
    }

    public function test_make_factory(): void
    {
        $response = Response::make('Hello', 201);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Hello', $response->getBody());
    }

    // ── Fluent setters ────────────────────────────────────────────────────────

    public function test_status_setter(): void
    {
        $response = (new Response())->status(404);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_body_setter(): void
    {
        $response = (new Response())->body('<h1>Test</h1>');
        $this->assertSame('<h1>Test</h1>', $response->getBody());
    }

    public function test_header_setter(): void
    {
        $response = (new Response())->header('Content-Type', 'text/plain');
        $this->assertSame('text/plain', $response->getHeaders()['Content-Type']);
    }

    public function test_with_headers(): void
    {
        $response = (new Response())->withHeaders([
            'X-Foo' => 'bar',
            'X-Baz' => 'qux',
        ]);
        $this->assertSame('bar', $response->getHeaders()['X-Foo']);
        $this->assertSame('qux', $response->getHeaders()['X-Baz']);
    }

    // ── JSON ──────────────────────────────────────────────────────────────────

    public function test_json_factory_sets_content_type(): void
    {
        $response = Response::json(['key' => 'value']);
        $this->assertStringContainsString('application/json', $response->getHeaders()['Content-Type']);
    }

    public function test_json_factory_encodes_data(): void
    {
        $response = Response::json(['name' => 'António']);
        $decoded  = json_decode($response->getBody(), true);
        $this->assertSame('António', $decoded['name']);
    }

    public function test_json_factory_default_status_200(): void
    {
        $this->assertSame(200, Response::json([])->getStatusCode());
    }

    public function test_json_factory_custom_status(): void
    {
        $this->assertSame(201, Response::json([], 201)->getStatusCode());
    }

    // ── Redirect ──────────────────────────────────────────────────────────────

    public function test_redirect_factory_sets_location(): void
    {
        $response = Response::redirect('/dashboard');
        $this->assertSame('/dashboard', $response->getHeaders()['Location']);
    }

    public function test_redirect_default_status_302(): void
    {
        $this->assertSame(302, Response::redirect('/home')->getStatusCode());
    }

    public function test_redirect_custom_status(): void
    {
        $this->assertSame(301, Response::redirect('/new', 301)->getStatusCode());
    }

    public function test_is_redirect_true_for_302(): void
    {
        $this->assertTrue(Response::redirect('/x')->isRedirect());
    }

    public function test_is_redirect_false_for_200(): void
    {
        $this->assertFalse(Response::make('ok')->isRedirect());
    }

    // ── Error factories ───────────────────────────────────────────────────────

    public function test_not_found_factory(): void
    {
        $this->assertSame(404, Response::notFound()->getStatusCode());
    }

    public function test_unauthorized_factory(): void
    {
        $this->assertSame(401, Response::unauthorized()->getStatusCode());
    }

    public function test_forbidden_factory(): void
    {
        $this->assertSame(403, Response::forbidden()->getStatusCode());
    }

    public function test_server_error_factory(): void
    {
        $this->assertSame(500, Response::serverError()->getStatusCode());
    }

    // ── is_successful ─────────────────────────────────────────────────────────

    public function test_is_successful_for_200(): void
    {
        $this->assertTrue(Response::make('ok')->isSuccessful());
    }

    public function test_is_successful_for_201(): void
    {
        $this->assertTrue(Response::make('', 201)->isSuccessful());
    }

    public function test_is_not_successful_for_404(): void
    {
        $this->assertFalse(Response::notFound()->isSuccessful());
    }

    // ── Body content ─────────────────────────────────────────────────────────

    public function test_custom_body(): void
    {
        $response = Response::notFound('<p>Custom 404</p>');
        $this->assertSame('<p>Custom 404</p>', $response->getBody());
    }
}