<?php

namespace Luany\Core\Psr;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Exposes a Luany application (a Router, or any callable Request → Response)
 * as a PSR-15 request handler, for PSR-15 servers and long-running workers
 * (RoadRunner, FrankenPHP worker mode, ReactPHP, …):
 *
 *   $handler  = new RequestHandler(Route::router());
 *   $handler  = new RequestHandler(fn (Request $r) => $kernel->handle($r));
 *   $response = $handler->handle($psrServerRequest);
 */
final class RequestHandler implements RequestHandlerInterface
{
    /** @var \Closure(Request): Response */
    private \Closure $app;

    /**
     * @param object|callable $app Object with handle(Request): Response, or callable(Request): Response
     */
    public function __construct(object|callable $app)
    {
        if (is_callable($app)) {
            $this->app = \Closure::fromCallable($app);
        } elseif (method_exists($app, 'handle')) {
            $this->app = fn (Request $request): Response => $app->handle($request);
        } else {
            throw new \InvalidArgumentException('RequestHandler expects a callable or an object with handle(Request): Response.');
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return PsrConverter::toPsrResponse(($this->app)(PsrConverter::fromPsrRequest($request)));
    }
}
