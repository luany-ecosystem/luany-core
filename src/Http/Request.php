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
    /** @var array<string, mixed> */
    private array $query;
    /** @var array<string, mixed> */
    private array $body;
    /** @var array<string, mixed> */
    private array $files;
    /** @var array<string, string> */
    private array $headers;
    /** @var array<string, mixed> */
    private array $server;
    /** @var array<string, mixed> */
    private array $cookies;

    /** @var array<int, string> Trusted proxy IPs / CIDR ranges (IPv4 or IPv6) */
    private static array $trustedProxies = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, mixed>  $files
     * @param array<string, string> $headers
     * @param array<string, mixed>  $server
     * @param array<string, mixed>  $cookies
     */
    public function __construct(
        string $method,
        string $uri,
        array  $query   = [],
        array  $body    = [],
        array  $files   = [],
        array  $headers = [],
        array  $server  = [],
        array  $cookies = []
    ) {
        $this->method  = strtoupper($method);
        $this->uri     = $uri;
        $this->query   = $query;
        $this->body    = $body;
        $this->files   = $files;
        $this->headers = $headers;
        $this->server  = $server;
        $this->cookies = $cookies;
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

        $uri = self::stripBasePath($uri, (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        $uri = '/' . trim($uri, '/');
        if ($uri === '') $uri = '/'; // @phpstan-ignore identical.alwaysFalse

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
            server:  $_SERVER,
            cookies: $_COOKIE,
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
    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * Get the parsed request body (POST fields or decoded JSON).
     *
     * Alias for all() restricted to body fields only — does not include
     * query-string values. Preferred when validating form/JSON submissions:
     *
     *   $data = validate($request->body(), ['name' => 'required|string']);
     *
     * For both body + query merged, use all().
     */
    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
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

    /** @return array<string, mixed>|null */
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

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    // ── Server ────────────────────────────────────────────────────────────────

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function hasCookie(string $key): bool
    {
        return isset($this->cookies[$key]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Client IP address.
     *
     * X-Forwarded-For is only honoured when REMOTE_ADDR is a trusted proxy
     * (see setTrustedProxies()). The chain is walked right-to-left and the
     * first address that is not itself a trusted proxy is returned, so
     * clients cannot spoof their IP by sending the header themselves.
     */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');

        if (!$this->isFromTrustedProxy()) {
            return $remote;
        }

        $forwarded = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded === '') {
            return $remote;
        }

        $chain = array_reverse(array_map('trim', explode(',', $forwarded)));

        foreach ($chain as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                break; // malformed entry: stop trusting the rest of the chain
            }
            if (!self::ipMatches($candidate, self::$trustedProxies)) {
                return $candidate;
            }
        }

        return $remote;
    }

    /**
     * Whether the request arrived over HTTPS (honours X-Forwarded-Proto
     * only from trusted proxies).
     */
    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if (!empty($https) && strtolower((string) $https) !== 'off') {
            return true;
        }

        if ($this->isFromTrustedProxy()) {
            $proto = strtolower(trim(explode(',', (string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            return $proto === 'https';
        }

        return false;
    }

    /**
     * Configure proxies whose X-Forwarded-* headers are trusted.
     * Accepts IPs and CIDR ranges, e.g. ['10.0.0.0/8', '127.0.0.1', '::1'].
     *
     * @param array<int, string> $proxies
     */
    public static function setTrustedProxies(array $proxies): void
    {
        self::$trustedProxies = array_values($proxies);
    }

    /** @return array<int, string> */
    public static function getTrustedProxies(): array
    {
        return self::$trustedProxies;
    }

    private function isFromTrustedProxy(): bool
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return $remote !== '' && self::ipMatches($remote, self::$trustedProxies);
    }

    /**
     * @param array<int, string> $ranges
     */
    private static function ipMatches(string $ip, array $ranges): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach ($ranges as $range) {
            [$subnet, $bits] = str_contains($range, '/')
                ? explode('/', $range, 2)
                : [$range, null];

            $subnetBin = @inet_pton($subnet);
            if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin)) {
                continue;
            }

            $maxBits = strlen($ipBin) * 8;
            $bits    = $bits === null ? $maxBits : (int) $bits;
            if ($bits < 0 || $bits > $maxBits) {
                continue;
            }

            $bytes = intdiv($bits, 8);
            $rest  = $bits % 8;

            if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                continue;
            }

            if ($rest === 0) {
                return true;
            }

            $mask = chr((0xFF << (8 - $rest)) & 0xFF);
            if ((($ipBin[$bytes] ?? "\0") & $mask) === (($subnetBin[$bytes] ?? "\0") & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the front-controller directory from the path for sub-folder
     * installs. Only strips on a path-segment boundary: with SCRIPT_NAME
     * "/app/index.php", "/app/x" → "/x" but "/application/x" is untouched.
     */
    public static function stripBasePath(string $uri, string $scriptName): string
    {
        $scriptDir = str_replace('\\', '/', dirname($scriptName));

        if ($scriptDir === '' || $scriptDir === '/' || $scriptDir === '.') {
            return $uri;
        }

        $scriptDir = rtrim($scriptDir, '/');

        if ($uri === $scriptDir) {
            return '/';
        }

        if (str_starts_with($uri, $scriptDir . '/')) {
            return substr($uri, strlen($scriptDir));
        }

        return $uri;
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function url(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host   = $this->server['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . $this->uri;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return array<string, string> */
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