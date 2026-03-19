<?php

namespace Luany\Core\Exceptions;

/**
 * MethodNotAllowedException
 *
 * Thrown by the Router when a URI matches a registered route but the HTTP
 * method used in the request is not allowed for that route.
 *
 * This is distinct from RouteNotFoundException (404 — URI not found at all).
 * The HTTP spec requires a 405 response with an Allow header listing the
 * methods that ARE accepted for that URI.
 *
 * Caught by the Kernel's exception handler, which should:
 *   1. Set the response status to 405
 *   2. Set the Allow header to $exception->getAllowedMethods()
 *
 * Example:
 *   Route registered: POST /users
 *   Request:          DELETE /users
 *   → MethodNotAllowedException(['POST']) with code 405
 */
class MethodNotAllowedException extends \RuntimeException
{
    /** @var string[] */
    private array $allowedMethods;

    /**
     * @param string   $method         The HTTP method used in the request
     * @param string   $uri            The request URI
     * @param string[] $allowedMethods Methods that ARE registered for this URI
     */
    public function __construct(string $method, string $uri, array $allowedMethods)
    {
        $this->allowedMethods = array_map('strtoupper', $allowedMethods);

        $allowed = implode(', ', $this->allowedMethods);

        parent::__construct(
            "Method [{$method}] not allowed for [{$uri}]. Allowed: {$allowed}",
            405
        );
    }

    /**
     * Returns the list of HTTP methods allowed for the matched URI.
     * Use this to set the Allow header on the 405 response.
     *
     * @return string[]
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

    /**
     * Returns the Allow header value, ready to set on the response.
     *
     * Example: "GET, POST, PUT"
     */
    public function getAllowHeaderValue(): string
    {
        return implode(', ', $this->allowedMethods);
    }
}
