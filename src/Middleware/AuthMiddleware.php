<?php

namespace Luany\Core\Middleware;

/**
 * Auth Middleware
 * Ensures user is authenticated before accessing protected routes
 */
class AuthMiddleware
{
    /**
     * Handle the middleware logic
     */
    public function handle(): void
    {
        if (!isset($_SESSION['user_id'])) {
            flash('warning', 'Você precisa fazer login para acessar esta página');
            redirect('auth');
            exit;
        }
    }
}