<?php

namespace App\Http\Middleware;

use App\Support\StaffRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts Admin-only dashboard modules to authenticated staff with an Admin appointment.
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        // Guests are redirected to /login by the `auth` middleware on this
        // route group. This gate only answers "is this authenticated staff an Admin?"
        // Authenticated BHW/BNS/BSPO must receive 403, not a login redirect.
        $role = StaffRole::normalize(Auth::user()?->role);

        if ($role !== StaffRole::ADMIN) {
            abort(403, 'Administrator access is required for this module.');
        }

        return $next($request);
    }
}
