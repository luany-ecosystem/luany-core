<?php

namespace Luany\Core\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;

/**
 * MiddlewareInterface
 *
 * Contract for all Luany middleware.
 * Each middleware receives the Request and a $next callable,
 * and must return a Response.
 *
 * Usage:
 *   class AuthMiddleware implements MiddlewareInterface
 *   {
 *       public function handle(Request $request, callable $next): Response
 *       {
 *           if (!isset($_SESSION['user_id'])) {
 *               return Response::redirect('/login');
 *           }
 *           return $next($request);
 *       }
 *   }
 */
interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}