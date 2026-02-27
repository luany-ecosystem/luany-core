<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    private function makeRequest(
        string $method = 'GET',
        string $uri    = '/',
        array  $query  = [],
        array  $body   = [],
        array  $files  = [],
        array  $headers = [],
        array  $server  = []
    ): Request {
        return new Request($method, $uri, $query, $body, $files, $headers, $server);
    }

    // ── Method ────────────────────────────────────────────────────────────────

    public function test_method_is_uppercased(): void
    {
        $request = $this->makeRequest('get');
        $this->assertSame('GET', $request->method());
    }

    public function test_is_get(): void
    {
        $this->assertTrue($this->makeRequest('GET')->isGet());
        $this->assertFalse($this->makeRequest('POST')->isGet());
    }

    public function test_is_post(): void
    {
        $this->assertTrue($this->makeRequest('POST')->isPost());
    }

    public function test_is_put(): void
    {
        $this->assertTrue($this->makeRequest('PUT')->isPut());
    }

    public function test_is_patch(): void
    {
        $this->assertTrue($this->makeRequest('PATCH')->isPatch());
    }

    public function test_is_delete(): void
    {
        $this->assertTrue($this->makeRequest('DELETE')->isDelete());
    }

    public function test_is_method(): void
    {
        $this->assertTrue($this->makeRequest('POST')->isMethod('post'));
        $this->assertTrue($this->makeRequest('POST')->isMethod('POST'));
        $this->assertFalse($this->makeRequest('POST')->isMethod('GET'));
    }

    // ── URI ───────────────────────────────────────────────────────────────────

    public function test_uri_is_stored(): void
    {
        $this->assertSame('/users/42', $this->makeRequest('GET', '/users/42')->uri());
    }

    // ── Input ─────────────────────────────────────────────────────────────────

    public function test_input_reads_body(): void
    {
        $request = $this->makeRequest('POST', '/', [], ['name' => 'António']);
        $this->assertSame('António', $request->input('name'));
    }

    public function test_input_reads_query(): void
    {
        $request = $this->makeRequest('GET', '/', ['page' => '2']);
        $this->assertSame('2', $request->input('page'));
    }

    public function test_input_body_takes_precedence_over_query(): void
    {
        $request = $this->makeRequest('POST', '/', ['key' => 'from-query'], ['key' => 'from-body']);
        $this->assertSame('from-body', $request->input('key'));
    }

    public function test_input_returns_default_when_missing(): void
    {
        $this->assertNull($this->makeRequest()->input('missing'));
        $this->assertSame('default', $this->makeRequest()->input('missing', 'default'));
    }

    public function test_query_method(): void
    {
        $request = $this->makeRequest('GET', '/', ['sort' => 'asc']);
        $this->assertSame('asc', $request->query('sort'));
        $this->assertNull($request->query('missing'));
    }

    public function test_post_method(): void
    {
        $request = $this->makeRequest('POST', '/', [], ['email' => 'test@test.com']);
        $this->assertSame('test@test.com', $request->post('email'));
    }

    public function test_all_merges_query_and_body(): void
    {
        $request = $this->makeRequest('POST', '/', ['page' => '1'], ['name' => 'test']);
        $all = $request->all();
        $this->assertSame('1', $all['page']);
        $this->assertSame('test', $all['name']);
    }

    public function test_only(): void
    {
        $request = $this->makeRequest('POST', '/', [], ['name' => 'a', 'email' => 'b', 'age' => 'c']);
        $only = $request->only(['name', 'email']);
        $this->assertArrayHasKey('name', $only);
        $this->assertArrayHasKey('email', $only);
        $this->assertArrayNotHasKey('age', $only);
    }

    public function test_except(): void
    {
        $request = $this->makeRequest('POST', '/', [], ['name' => 'a', 'password' => 'secret']);
        $except = $request->except(['password']);
        $this->assertArrayHasKey('name', $except);
        $this->assertArrayNotHasKey('password', $except);
    }

    public function test_has(): void
    {
        $request = $this->makeRequest('POST', '/', [], ['name' => 'António']);
        $this->assertTrue($request->has('name'));
        $this->assertFalse($request->has('missing'));
    }

    public function test_filled_returns_false_for_empty_string(): void
    {
        $request = $this->makeRequest('POST', '/', [], ['name' => '']);
        $this->assertFalse($request->filled('name'));
    }

    public function test_filled_returns_true_for_non_empty(): void
    {
        $request = $this->makeRequest('POST', '/', [], ['name' => 'António']);
        $this->assertTrue($request->filled('name'));
    }

    // ── Headers ───────────────────────────────────────────────────────────────

    public function test_header_retrieval(): void
    {
        $request = $this->makeRequest('GET', '/', [], [], [], ['Accept' => 'application/json']);
        $this->assertSame('application/json', $request->header('Accept'));
    }

    public function test_header_case_insensitive(): void
    {
        $request = $this->makeRequest('GET', '/', [], [], [], ['Content-Type' => 'text/html']);
        $this->assertSame('text/html', $request->header('content-type'));
        $this->assertSame('text/html', $request->header('CONTENT-TYPE'));
    }

    public function test_header_default_when_missing(): void
    {
        $this->assertNull($this->makeRequest()->header('X-Missing'));
        $this->assertSame('fallback', $this->makeRequest()->header('X-Missing', 'fallback'));
    }

    // ── Ajax & JSON ───────────────────────────────────────────────────────────

    public function test_is_ajax_with_xhr_header(): void
    {
        $request = $this->makeRequest('GET', '/', [], [], [], ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertTrue($request->isAjax());
    }

    public function test_is_not_ajax_without_header(): void
    {
        $this->assertFalse($this->makeRequest()->isAjax());
    }

    public function test_expects_json_with_accept_header(): void
    {
        $request = $this->makeRequest('GET', '/', [], [], [], ['Accept' => 'application/json']);
        $this->assertTrue($request->expectsJson());
    }

    // ── File ─────────────────────────────────────────────────────────────────

    public function test_has_file_false_when_not_present(): void
    {
        $this->assertFalse($this->makeRequest()->hasFile('avatar'));
    }

    public function test_file_returns_null_when_not_present(): void
    {
        $this->assertNull($this->makeRequest()->file('avatar'));
    }

    // ── Server ────────────────────────────────────────────────────────────────

    public function test_server_reads_server_array(): void
    {
        $request = $this->makeRequest('GET', '/', [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $this->assertSame('127.0.0.1', $request->server('REMOTE_ADDR'));
    }
}