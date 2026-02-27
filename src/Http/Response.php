<?php

namespace Luany\Core\Http;

/**
 * Response
 *
 * Encapsulates an HTTP response — status code, headers and body.
 * Responses are built fluently and sent via send().
 *
 * Router::dispatch() sends the Response returned by the route action.
 * Controllers return a Response, never echo directly.
 *
 * Usage:
 *   return Response::make('Hello World');
 *   return Response::json(['status' => 'ok']);
 *   return Response::redirect('/dashboard');
 *   return (new Response())->status(404)->body('<h1>Not Found</h1>');
 */
class Response
{
    private int    $statusCode = 200;
    private array  $headers    = [];
    private string $body       = '';

    private static array $statusTexts = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
    ];

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body       = $body;
        $this->statusCode = $status;
        $this->headers    = $headers;
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    public static function make(string $body = '', int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=UTF-8';
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, $headers);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return (new self('', $status))->header('Location', $url);
    }

    public static function notFound(string $body = '<h1>404 — Not Found</h1>'): self
    {
        return new self($body, 404);
    }

    public static function unauthorized(string $body = '<h1>401 — Unauthorized</h1>'): self
    {
        return new self($body, 401);
    }

    public static function forbidden(string $body = '<h1>403 — Forbidden</h1>'): self
    {
        return new self($body, 403);
    }

    public static function serverError(string $body = '<h1>500 — Server Error</h1>'): self
    {
        return new self($body, 500);
    }

    // ── Fluent setters ────────────────────────────────────────────────────────

    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function isRedirect(): bool
    {
        return in_array($this->statusCode, [301, 302, 303, 307, 308], true);
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    // ── Send ──────────────────────────────────────────────────────────────────

    /**
     * Send the response to the client.
     * Call once, at the end of the request lifecycle.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            $text = self::$statusTexts[$this->statusCode] ?? 'Unknown';
            header("HTTP/1.1 {$this->statusCode} {$text}");

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->body;
    }
}