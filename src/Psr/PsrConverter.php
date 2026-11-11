<?php

namespace Luany\Core\Psr;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Converts between Luany's Request / Response and PSR-7 messages.
 *
 * Uses the PSR-17 factories from Psr17 (configured or auto-detected).
 */
final class PsrConverter
{
    // ── Request ─────────────────────────────────────────────────────────────

    public static function toPsrRequest(Request $request): ServerRequestInterface
    {
        $server = $request->serverParams();
        $uri    = self::absoluteUri($request, $server);

        $psr = Psr17::serverRequests()->createServerRequest($request->method(), $uri, $server)
            ->withQueryParams($request->queryParams())
            ->withCookieParams($request->cookies());

        foreach ($request->headers() as $name => $value) {
            $psr = $psr->withHeader((string) $name, (string) $value);
        }

        $body = $request->body();
        $psr  = $psr->withParsedBody($body);

        if ($body !== [] && str_contains(strtolower((string) $request->header('Content-Type', '')), 'json')) {
            $psr = $psr->withBody(Psr17::streams()->createStream((string) json_encode($body)));
        }

        $files = self::toPsrFiles($request->files());
        if ($files !== []) {
            $psr = $psr->withUploadedFiles($files);
        }

        return $psr;
    }

    public static function fromPsrRequest(ServerRequestInterface $psr): Request
    {
        $headers = [];
        foreach ($psr->getHeaders() as $name => $values) {
            $headers[self::headerName((string) $name)] = implode(', ', $values);
        }

        $body = $psr->getParsedBody();
        if (!is_array($body)) {
            $body = is_object($body) ? get_object_vars($body) : [];
        }

        if ($body === [] && str_contains(strtolower($psr->getHeaderLine('Content-Type')), 'json')) {
            $stream = $psr->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $decoded = json_decode((string) $stream, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $path = $psr->getUri()->getPath();

        return new Request(
            method:  $psr->getMethod(),
            uri:     '/' . trim($path === '' ? '/' : $path, '/'),
            query:   $psr->getQueryParams(),
            body:    $body,
            files:   self::fromPsrFiles($psr->getUploadedFiles()),
            headers: $headers,
            server:  $psr->getServerParams(),
            cookies: $psr->getCookieParams(),
        );
    }

    // ── Response ────────────────────────────────────────────────────────────

    public static function toPsrResponse(Response $response): ResponseInterface
    {
        $psr = Psr17::responses()->createResponse($response->getStatusCode())
            ->withProtocolVersion($response->getProtocolVersion())
            ->withBody(Psr17::streams()->createStream($response->getBody()));

        foreach ($response->getHeaders() as $name => $value) {
            $psr = $psr->withHeader((string) $name, (string) $value);
        }

        foreach ($response->getCookies() as $cookie) {
            $psr = $psr->withAddedHeader('Set-Cookie', $cookie);
        }

        return $psr;
    }

    public static function fromPsrResponse(ResponseInterface $psr): Response
    {
        $stream = $psr->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $response = Response::make((string) $stream, $psr->getStatusCode())
            ->setProtocolVersion($psr->getProtocolVersion());

        foreach ($psr->getHeaders() as $name => $values) {
            if (strcasecmp((string) $name, 'Set-Cookie') === 0) {
                foreach ($values as $cookie) {
                    $response->rawCookie($cookie);
                }
                continue;
            }
            $response->header((string) $name, implode(', ', $values));
        }

        return $response;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $server
     */
    private static function absoluteUri(Request $request, array $server): string
    {
        $host  = (string) ($server['HTTP_HOST'] ?? $request->header('Host', 'localhost'));
        $query = $request->queryParams() === [] ? '' : '?' . http_build_query($request->queryParams());

        return ($request->isSecure() ? 'https' : 'http') . '://' . $host . $request->uri() . $query;
    }

    private static function headerName(string $name): string
    {
        return ucwords(strtolower($name), '-');
    }

    /**
     * @param array<string, mixed> $files $_FILES-style array (single files)
     * @return array<string, UploadedFileInterface>
     */
    private static function toPsrFiles(array $files): array
    {
        $factory = Psr17::uploadedFiles();
        if ($factory === null) {
            return [];
        }

        $out = [];
        foreach ($files as $field => $file) {
            if (!is_array($file) || !isset($file['tmp_name']) || is_array($file['tmp_name'])) {
                continue; // multi-file inputs are not converted
            }
            $tmp    = (string) $file['tmp_name'];
            $stream = $tmp !== '' && is_readable($tmp)
                ? Psr17::streams()->createStreamFromFile($tmp)
                : Psr17::streams()->createStream('');

            $out[(string) $field] = $factory->createUploadedFile(
                $stream,
                isset($file['size']) ? (int) $file['size'] : null,
                (int) ($file['error'] ?? UPLOAD_ERR_OK),
                isset($file['name']) ? (string) $file['name'] : null,
                isset($file['type']) ? (string) $file['type'] : null,
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private static function fromPsrFiles(array $files): array
    {
        $out = [];
        foreach ($files as $field => $file) {
            if (!$file instanceof UploadedFileInterface) {
                continue;
            }
            $uri = $file->getError() === UPLOAD_ERR_OK ? $file->getStream()->getMetadata('uri') : null;
            if (!is_string($uri) || !is_file($uri)) {
                $uri = '';
                if ($file->getError() === UPLOAD_ERR_OK) {
                    $uri = (string) tempnam(sys_get_temp_dir(), 'luany_upload_');
                    file_put_contents($uri, (string) $file->getStream());
                }
            }

            $out[(string) $field] = [
                'name'     => (string) $file->getClientFilename(),
                'type'     => (string) $file->getClientMediaType(),
                'tmp_name' => $uri,
                'error'    => $file->getError(),
                'size'     => (int) $file->getSize(),
            ];
        }
        return $out;
    }
}
