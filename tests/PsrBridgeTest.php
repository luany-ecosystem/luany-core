<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\Pipeline;
use Luany\Core\Psr\Psr17;
use Luany\Core\Psr\RequestHandler;
use Luany\Core\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** PSR-15: adds a request header and a response header. */
final class PsrTagMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withHeader('X-Tag', 'psr15'))->withHeader('X-Processed', 'yes');
    }
}

/** PSR-15: short-circuits with its own response. */
final class PsrDenyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return (new Psr17Factory())->createResponse(401)->withBody((new Psr17Factory())->createStream('denied'));
    }
}

final class PsrNeedsDependency implements \Luany\Core\Middleware\MiddlewareInterface
{
    public function __construct(public readonly \ArrayObject $dep) {}

    public function handle(Request $request, callable $next): Response
    {
        return $next($request)->header('X-Dep', (string) $this->dep['v']);
    }
}

class PsrBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        Psr17::reset();
        Pipeline::setDefaultResolver(null);
    }

    protected function tearDown(): void
    {
        Psr17::reset();
        Pipeline::setDefaultResolver(null);
    }

    // ── PSR-7 conversion ─────────────────────────────────────────────────────

    public function test_request_round_trip(): void
    {
        $request = new Request(
            method:  'POST',
            uri:     '/users/7',
            query:   ['page' => '2'],
            body:    ['name' => 'Ana'],
            headers: ['Accept' => 'application/json', 'X-Custom' => 'a'],
            server:  ['HTTP_HOST' => 'example.ao', 'REMOTE_ADDR' => '10.0.0.1'],
            cookies: ['sid' => 'abc'],
        );

        $psr = $request->toPsr();
        $this->assertSame('POST', $psr->getMethod());
        $this->assertSame('http://example.ao/users/7?page=2', (string) $psr->getUri());
        $this->assertSame(['name' => 'Ana'], $psr->getParsedBody());
        $this->assertSame('a', $psr->getHeaderLine('X-Custom'));
        $this->assertSame(['sid' => 'abc'], $psr->getCookieParams());

        $back = Request::fromPsr($psr);
        $this->assertSame('/users/7', $back->uri());
        $this->assertSame('2', $back->query('page'));
        $this->assertSame('Ana', $back->input('name'));
        $this->assertSame('a', $back->header('x-custom'));
        $this->assertSame('abc', $back->cookie('sid'));
        $this->assertSame('10.0.0.1', $back->ip());
        $this->assertTrue($back->expectsJson());
    }

    public function test_json_body_from_psr_stream(): void
    {
        $f   = new Psr17Factory();
        $psr = $f->createServerRequest('POST', 'https://x.ao/api')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($f->createStream('{"qty":3}'));

        $this->assertSame(3, Request::fromPsr($psr)->input('qty'));
    }

    public function test_response_round_trip_keeps_status_headers_and_cookies(): void
    {
        $response = Response::make('hello', 201)
            ->header('X-A', '1')
            ->cookie('a', '1')
            ->cookie('b', '2');

        $psr = $response->toPsr();
        $this->assertSame(201, $psr->getStatusCode());
        $this->assertSame('hello', (string) $psr->getBody());
        $this->assertCount(2, $psr->getHeader('Set-Cookie'));

        $back = Response::fromPsr($psr);
        $this->assertSame(201, $back->getStatusCode());
        $this->assertSame('hello', $back->getBody());
        $this->assertSame('1', $back->getHeaders()['X-A']);
        $this->assertCount(2, $back->getCookies());
    }

    public function test_uploaded_files_are_converted(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, 'PNGDATA');

        $request = new Request('POST', '/upload', files: [
            'avatar' => ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 7],
        ]);

        $file = $request->toPsr()->getUploadedFiles()['avatar'];
        $this->assertSame('a.png', $file->getClientFilename());
        $this->assertSame('PNGDATA', (string) $file->getStream());

        $back = Request::fromPsr($request->toPsr())->file('avatar');
        $this->assertSame('a.png', $back['name']);
        $this->assertSame('PNGDATA', file_get_contents($back['tmp_name']));
        @unlink($tmp);
    }

    // ── PSR-15 in the pipeline ───────────────────────────────────────────────

    public function test_psr15_middleware_instance_and_class_name_run_in_routes(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/a', fn (Request $r) => 'tag=' . $r->header('X-Tag'))
            ->middleware(new PsrTagMiddleware());
        $router->addRoute('GET', '/b', fn (Request $r) => 'tag=' . $r->header('X-Tag'))
            ->middleware(PsrTagMiddleware::class);

        foreach (['/a', '/b'] as $uri) {
            $response = $router->handle(new Request('GET', $uri));
            $this->assertSame('tag=psr15', $response->getBody());
            $this->assertSame('yes', $response->getHeaders()['X-Processed']);
        }
    }

    public function test_psr15_middleware_can_short_circuit(): void
    {
        $response = (new Pipeline())
            ->send(new Request('GET', '/'))
            ->through([new PsrDenyMiddleware()])
            ->then(fn () => Response::make('should not run'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('denied', $response->getBody());
    }

    public function test_invalid_middleware_type_has_clear_message(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSR-15');
        (new Pipeline())->send(new Request('GET', '/'))->through([new \stdClass()])->then(fn () => Response::make());
    }

    // ── PSR-15 request handler ───────────────────────────────────────────────

    public function test_router_as_psr15_request_handler(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/hello/{name}', fn (Request $r, string $name) => Response::make("hi {$name}")->cookie('seen', '1'));

        $psrResponse = (new RequestHandler($router))->handle(
            (new Psr17Factory())->createServerRequest('GET', 'http://localhost/hello/ana')
        );

        $this->assertSame(200, $psrResponse->getStatusCode());
        $this->assertSame('hi ana', (string) $psrResponse->getBody());
        $this->assertStringStartsWith('seen=1', $psrResponse->getHeaderLine('Set-Cookie'));
    }

    public function test_request_handler_accepts_callables(): void
    {
        $handler = new RequestHandler(fn (Request $r) => Response::json(['m' => $r->method()]));
        $response = $handler->handle((new Psr17Factory())->createServerRequest('PUT', '/x'));

        $this->assertSame('{"m":"PUT"}', (string) $response->getBody());
    }

    // ── PSR-11 & PSR-17 configuration ────────────────────────────────────────

    public function test_psr11_container_as_resolver(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed { return new PsrNeedsDependency(new \ArrayObject(['v' => 'from-container'])); }
            public function has(string $id): bool { return $id === PsrNeedsDependency::class; }
        };
        Pipeline::useContainer($container);

        $response = (new Pipeline())
            ->send(new Request('GET', '/'))
            ->through([PsrNeedsDependency::class])
            ->then(fn () => Response::make('ok'));

        $this->assertSame('from-container', $response->getHeaders()['X-Dep']);
    }

    public function test_psr17_auto_discovery_and_validation(): void
    {
        $this->assertInstanceOf(Psr17Factory::class, Psr17::responses());

        $this->expectException(\InvalidArgumentException::class);
        Psr17::use(new \stdClass());
    }
}
