<?php

namespace Luany\Core\Http;

/**
 * Request
 *
 * Encapsulates the current HTTP request.
 * Reads from $_SERVER, $_GET, $_POST, $_FILES and php://input.
 *
 * Lifecycle: one instance is created at bootstrap and passed
 * through the middleware pipeline and into the route action.
 */
class Request
{
    private string $method;
    private string $uri;
    private array  $query;
    private array  $body;
    private array  $files;
    private array  $headers;
    private array  $server;

    public function __construct(
        string $method,
        string $uri,
        array  $query   = [],
        array  $body    = [],
        array  $files   = [],
        array  $headers = [],
        array  $server  = []
    ) {
        $this->method  = strtoupper($method);
        $this->uri     = $uri;
        $this->query   = $query;
        $this->body    = $body;
        $this->files   = $files;
        $this->headers = $headers;
        $this->server  = $server;
    }

    // ── Factory ────────────────────────────────────────────────────────────────

    /**
     * Create a Request from PHP globals.
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Method override via hidden _method field (for HTML forms)
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper($_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        // Strip script directory prefix (sub-folder installs)
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptDir && $scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        $uri = '/' . trim($uri, '/');
        if ($uri === '') $uri = '/';

        // Parse JSON body if Content-Type is application/json
        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        return new self(
            method:  $method,
            uri:     $uri,
            query:   $_GET,
            body:    $body,
            files:   $_FILES,
            headers: self::parseHeaders(),
            server:  $_SERVER
        );
    }

    // ── Method & URI ───────────────────────────────────────────────────────────

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isGet(): bool    { return $this->method === 'GET'; }
    public function isPost(): bool   { return $this->method === 'POST'; }
    public function isPut(): bool    { return $this->method === 'PUT'; }
    public function isPatch(): bool  { return $this->method === 'PATCH'; }
    public function isDelete(): bool { return $this->method === 'DELETE'; }
    public function isAjax(): bool
    {
        return ($this->headers['X-Requested-With'] ?? '') === 'XMLHttpRequest';
    }

    public function expectsJson(): bool
    {
        $accept = $this->headers['Accept'] ?? '';
        return str_contains($accept, 'application/json') || $this->isAjax();
    }

    // ── Input ─────────────────────────────────────────────────────────────────

    /**
     * Get a value from query string, body, or both.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Get all input (query + body merged).
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    public function except(array $keys): array
    {
        $all = $this->all();
        return array_diff_key($all, array_flip($keys));
    }

    public function has(string $key): bool
    {
        return isset($this->body[$key]) || isset($this->query[$key]);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);
        return $value !== null && $value !== '';
    }

    // ── Files ─────────────────────────────────────────────────────────────────

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    // ── Headers ───────────────────────────────────────────────────────────────

    public function header(string $key, mixed $default = null): mixed
    {
        // Case-insensitive lookup
        $normalized = $this->normalizeHeaderName($key);
        foreach ($this->headers as $name => $value) {
            if ($this->normalizeHeaderName($name) === $normalized) {
                return $value;
            }
        }
        return $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    // ── Server ────────────────────────────────────────────────────────────────

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function ip(): string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function url(): string
    {
        $scheme = (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $this->server['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . $this->uri;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $name = ucwords(strtolower($name), '-');
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = str_replace('_', '-', $key);
                $name = ucwords(strtolower($name), '-');
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private function normalizeHeaderName(string $name): string
    {
        return strtolower(str_replace(['_', ' '], '-', $name));
    }
}