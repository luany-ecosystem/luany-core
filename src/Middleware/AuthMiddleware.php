<?php

namespace Luany\Core\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;

/**
 * AuthMiddleware
 *
 * Ensures the user is authenticated before accessing protected routes.
 * Reads $_SESSION['user_id'] — set this in your login controller.
 *
 * Returns a 302 redirect to /login if not authenticated.
 * The redirect path can be overridden by extending this class.
 */
class AuthMiddleware implements MiddlewareInterface
{
    protected string $redirectTo = '/login';

    public function handle(Request $request, callable $next): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            return Response::redirect($this->redirectTo);
        }

        return $next($request);
    }
}