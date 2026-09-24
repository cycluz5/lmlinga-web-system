<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Offline\OfflineSyncException;
use App\Support\StaffAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects authenticated staff whose OWN account is inactive or soft-deleted.
 *
 * ACTOR vs TARGET:
 * - ACTOR = owner of this browser session (session auth id / Auth::user()).
 * - TARGET = route {id} on User Management activate/deactivate/destroy.
 *
 * This middleware evaluates ONLY the ACTOR. Route {id} is never used to decide
 * whether the current session should be torn down. Deactivating Health Worker 15
 * must not log out Admin 1 who owns the session performing the request.
 *
 * A deactivated Health Worker is forced to /login on THEIR next protected
 * request when THEIR session's authenticated user is Suspended/Inactive.
 */
class EnsureStaffAccountIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $actorId = $this->resolveActorId($request);
        if ($actorId === null || $actorId === '') {
            return $next($request);
        }

        $actor = User::query()->whereKey($actorId)->first();

        if (! $actor instanceof User || ! $actor->isActive()) {
            return $this->rejectUnusableActorSession($request);
        }

        Auth::setUser($actor);
        $request->session()->put(Auth::guard()->getName(), $actor->getAuthIdentifier());

        return $next($request);
    }

    /**
     * Authenticated session owner only — never the User Management route {id}.
     */
    private function resolveActorId(Request $request): int|string|null
    {
        $sessionId = $request->session()->get(Auth::guard()->getName());
        if ($sessionId !== null && $sessionId !== '') {
            return $sessionId;
        }

        $user = Auth::user();

        return $user instanceof User ? $user->getAuthIdentifier() : null;
    }

    /**
     * Tear down THIS browser's session because ITS owner is unusable.
     * Must not be called because a TARGET account on the route is inactive.
     */
    private function rejectUnusableActorSession(Request $request): Response
    {
        StaffAuthenticator::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->is('offline') || $request->is('offline/*') || $request->expectsJson()) {
            throw OfflineSyncException::accountInactive();
        }

        return redirect()->route('login');
    }
}
