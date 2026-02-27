<?php

namespace Luany\Core\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;

/**
 * Pipeline
 *
 * Executes a chain of middleware around a core action.
 *
 * Each middleware wraps the next — the innermost callable
 * is the route action itself.
 *
 * Usage:
 *   $response = (new Pipeline())
 *       ->send($request)
 *       ->through([MyCustomMiddleware::class, LogMiddleware::class])
 *       ->then(fn(Request $req) => $controller->action($req));
 */
class Pipeline
{
    private Request $request;
    private array   $middleware = [];

    public function send(Request $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function through(array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    public function then(callable $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            fn(callable $next, $middleware) => fn(Request $req) => $this->resolve($middleware)->handle($req, $next),
            $destination
        );

        return $pipeline($this->request);
    }

    private function resolve($middleware): MiddlewareInterface
    {
        if (is_string($middleware)) {
            if (!class_exists($middleware)) {
                throw new \RuntimeException("Middleware class not found: {$middleware}");
            }
            $instance = new $middleware();
            if (!$instance instanceof MiddlewareInterface) {
                throw new \RuntimeException("Middleware [{$middleware}] must implement MiddlewareInterface");
            }
            return $instance;
        }

        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        throw new \RuntimeException('Invalid middleware — must be class name string or MiddlewareInterface instance');
    }
}