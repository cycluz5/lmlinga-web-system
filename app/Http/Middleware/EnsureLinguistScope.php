<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\StaffRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SR-06 deny-by-default scope for Linguist accounts.
 *
 * Runs on every web request (global, so future staff routes are covered automatically) but only acts on
 * staff routes (those using the `auth` middleware) when the signed-in user's role — read from the
 * database through the current appointment — is Linguist. Everything outside the allowlist is refused
 * with 403: household profiles, resident health records, spot mapping, requests, user management,
 * announcements, offline sync/bootstrap, exports, and any route added later. No effect for other roles,
 * guests, resident chatbot or public routes.
 */
class EnsureLinguistScope
{
    /** Route names a Linguist may use (LQ-1: own profile view and password change). */
    public const ALLOWED_ROUTES = [
        'language-review.index',
        'password.change.required',
        'password.change.store',
        'profile.show',
        'logout',
    ];

    public const DENIED_MESSAGE = 'Linguist accounts can only use Language Review.';

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if ($route === null || ! in_array('auth', $route->gatherMiddleware(), true)) {
            return $next($request);
        }

        $user = Auth::user();
        if (! $user instanceof User || ! StaffRole::isLinguist($user->role)) {
            return $next($request);
        }

        $name = (string) $route->getName();

        if (in_array($name, self::ALLOWED_ROUTES, true) || str_starts_with($name, 'language-review.')) {
            return $next($request);
        }

        // LQ-6: the staff dashboard (also the post-login / post-password-change target) → Language Review.
        if ($name === 'dashboard' && $request->isMethodSafe()) {
            return redirect()->route('language-review.index');
        }

        if ($request->expectsJson() || $request->isJson()) {
            return response()->json(['message' => self::DENIED_MESSAGE], 403);
        }

        abort(403, self::DENIED_MESSAGE);
    }
}
