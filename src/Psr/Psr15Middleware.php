<?php

namespace Luany\Core\Psr;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as Psr15MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs a PSR-15 middleware inside Luany's pipeline.
 *
 * The Pipeline wraps PSR-15 middleware automatically, so you can register
 * them like any other middleware:
 *
 *   Route::get('/api/x', [ApiController::class, 'x'])
 *       ->middleware(new \Middlewares\Cors($analyzer));
 *
 * Changes the PSR-15 middleware makes to the request (headers, parsed body,
 * query…) reach the next middleware / controller; its response (or the one
 * it decorates) comes back as a Luany Response.
 */
final class Psr15Middleware implements MiddlewareInterface
{
    public function __construct(private readonly Psr15MiddlewareInterface $middleware) {}

    public function handle(Request $request, callable $next): Response
    {
        $handler = new class ($next) implements RequestHandlerInterface {
            /** @param callable(Request): Response $next */
            public function __construct(private $next) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return PsrConverter::toPsrResponse(($this->next)(PsrConverter::fromPsrRequest($request)));
            }
        };

        return PsrConverter::fromPsrResponse(
            $this->middleware->process(PsrConverter::toPsrRequest($request), $handler)
        );
    }

    public function inner(): Psr15MiddlewareInterface
    {
        return $this->middleware;
    }
}
