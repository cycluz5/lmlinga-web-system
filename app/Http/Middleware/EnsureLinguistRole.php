<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\StaffRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SR-06: Language Review is for active Linguist accounts only. Admin and every health-worker role get 403
 * (owner decisions D-7 / LQ-2: Admin never views the queue or approves content).
 */
class EnsureLinguistRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isActive() || ! StaffRole::isLinguist($user->role)) {
            abort(403, 'Language Review is available to Linguist accounts only.');
        }

        return $next($request);
    }
}
