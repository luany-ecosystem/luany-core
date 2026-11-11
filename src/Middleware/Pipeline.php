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
 *
 * Dependency injection:
 *   Middleware given as class names are instantiated through a resolver.
 *   Without one, `new $class()` is used. Frameworks register their
 *   container once:
 *
 *   Pipeline::setDefaultResolver(fn (string $class) => $container->make($class));
 */
class Pipeline
{
    private Request $request;
    /** @var array<int, class-string|MiddlewareInterface|\Psr\Http\Server\MiddlewareInterface> */
    private array   $middleware = [];

    /** @var (callable(class-string): object)|null */
    private static $defaultResolver = null;

    /** @var (callable(class-string): object)|null */
    private $resolver;

    /**
     * @param (callable(class-string): object)|null $resolver Per-pipeline resolver
     *                                                        (falls back to the default resolver)
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * Register the process-wide class resolver used for middleware (and,
     * by the Router, for controllers). Pass null to restore `new $class()`.
     *
     * @param (callable(class-string): object)|null $resolver
     */
    public static function setDefaultResolver(?callable $resolver): void
    {
        self::$defaultResolver = $resolver;
    }

    /**
     * @return (callable(class-string): object)|null
     */
    public static function getDefaultResolver(): ?callable
    {
        return self::$defaultResolver;
    }

    /**
     * Instantiate a class through the given resolver, the default resolver,
     * or `new $class()` as a last resort.
     *
     * @param class-string                          $class
     * @param (callable(class-string): object)|null $resolver
     */
    public static function instantiate(string $class, ?callable $resolver = null): object
    {
        $resolver ??= self::$defaultResolver;

        return $resolver !== null ? $resolver($class) : new $class();
    }

    public function send(Request $request): self
    {
        $this->request = $request;
        return $this;
    }

    /** @param array<int, class-string|MiddlewareInterface|\Psr\Http\Server\MiddlewareInterface> $middleware */
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

    /**
     * Resolve a PSR-11 container as the default resolver: classes it knows
     * come from the container, anything else is built with `new`.
     */
    public static function useContainer(\Psr\Container\ContainerInterface $container): void
    {
        self::setDefaultResolver(
            static fn (string $class): object => $container->has($class)
                ? $container->get($class)
                : new $class()
        );
    }

    /**
     * Luany middleware as-is; PSR-15 middleware (instances or class names)
     * wrapped in Psr\Psr15Middleware.
     *
     * @param class-string<MiddlewareInterface>|MiddlewareInterface|\Psr\Http\Server\MiddlewareInterface $middleware
     */
    private function resolve(mixed $middleware): MiddlewareInterface
    {
        if (is_string($middleware)) {
            if (!class_exists($middleware)) {
                throw new \RuntimeException("Middleware class not found: {$middleware}");
            }
            $middleware = self::instantiate($middleware, $this->resolver);
        }

        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if ($middleware instanceof \Psr\Http\Server\MiddlewareInterface) {
            return new \Luany\Core\Psr\Psr15Middleware($middleware);
        }

        $type = is_object($middleware) ? get_class($middleware) : gettype($middleware);
        throw new \RuntimeException(
            "Middleware [{$type}] must implement Luany\\Core\\Middleware\\MiddlewareInterface or PSR-15's Psr\\Http\\Server\\MiddlewareInterface"
        );
    }
}