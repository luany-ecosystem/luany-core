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
    /** @var array<string, string> */
    private array  $headers    = [];
    private string $body       = '';
    /** @var array<int, string> Raw Set-Cookie header values (multiple allowed) */
    private array  $cookies    = [];
    private string $protocolVersion = '1.1';

    /** @var array<int, string> */
    private static array $statusTexts = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => "I'm a teapot",
        419 => 'Page Expired',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Content',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    ];

    /** @param array<string, string> $headers */
    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body       = $body;
        $this->statusCode = $status;
        $this->headers    = $headers;
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    /** @param array<string, string> $headers */
    public static function make(string $body = '', int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers);
    }

    /** @param array<string, string> $headers */
    /**
     * JSON response. Invalid UTF-8 is replaced (U+FFFD) instead of producing an
     * empty body; any other encoding error throws \JsonException.
     *
     * @param array<string, string> $headers
     * @throws \JsonException
     */
    public static function json(
        mixed $data,
        int $status = 200,
        array $headers = [],
        int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ): self {
        $headers['Content-Type'] = 'application/json; charset=UTF-8';
        $body = json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        return new self($body, $status, $headers);
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

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /**
     * Queue a Set-Cookie header. Can be called multiple times.
     *
     * Defaults are secure: HttpOnly and SameSite=Lax. Pass secure: true on
     * HTTPS sites.
     */
    public function cookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        if ($name === '' || preg_match('/[=,; \t\r\n\013\014]/', $name) === 1) {
            throw new \InvalidArgumentException("Invalid cookie name [{$name}].");
        }

        $sameSite = ucfirst(strtolower($sameSite));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException("Invalid SameSite value [{$sameSite}].");
        }
        if ($sameSite === 'None') {
            $secure = true; // browsers reject SameSite=None without Secure
        }

        $parts = [$name . '=' . rawurlencode($value)];
        if ($expires !== 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT';
            $parts[] = 'Max-Age=' . max(0, $expires - time());
        }
        $parts[] = 'Path=' . $path;
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }
        if ($secure) {
            $parts[] = 'Secure';
        }
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = 'SameSite=' . $sameSite;

        $this->cookies[] = implode('; ', $parts);
        return $this;
    }

    /**
     * Expire a cookie in the browser.
     */
    public function forgetCookie(string $name, string $path = '/', string $domain = ''): self
    {
        return $this->cookie($name, '', time() - 3600, $path, $domain);
    }

    public function setProtocolVersion(string $version): self
    {
        $this->protocolVersion = $version;
        return $this;
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    /** @return array<int, string> Raw Set-Cookie values */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
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
            header("HTTP/{$this->protocolVersion} {$this->statusCode} {$text}", true, $this->statusCode);

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }

            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }

        echo $this->body;
    }
}