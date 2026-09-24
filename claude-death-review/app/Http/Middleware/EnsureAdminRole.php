<?php

namespace App\Http\Middleware;

use App\Support\UiRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts Admin-only dashboard modules to the Admin shell role.
 * Aligns route access with shared sidebar visibility (User Management,
 * Household Requests, Death Requests, and related Health Worker View/Edit pages).
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! UiRole::isAdmin()) {
            abort(403, 'Administrator access is required for this module.');
        }

        return $next($request);
    }
}
