<?php

namespace Luany\Core\Exceptions;

/**
 * RouteNotFoundException
 *
 * Thrown by the Router when no route matches the incoming request.
 * Caught by the Kernel's handleException() which delegates to the
 * application's Handler::render() for a styled 404 response.
 */
class RouteNotFoundException extends \RuntimeException
{
    public function __construct(string $method, string $uri)
    {
        parent::__construct(
            "No route matched [{$method}] {$uri}",
            404
        );
    }
}