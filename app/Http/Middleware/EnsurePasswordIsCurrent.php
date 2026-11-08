<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password changes are voluntary for staff accounts.
 *
 * This middleware is retained for route/config compatibility but no longer
 * forces workers to change their password before accessing the application.
 * Workers may update their password from User Profile.
 */
class EnsurePasswordIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
