<?php

namespace App\Http\Middleware;

use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the UI shell role in session across authenticated dashboard navigation.
 * Optional ?role= seeds/updates the session on GET/HEAD only for legacy demo shells,
 * then redirects without the query. Never grants authorization to protected routes.
 *
 * Apply only to authenticated dashboard-related route groups.
 */
class PersistUiRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $queryRole = UiRole::normalize((string) $request->query('role', ''));

        if ($queryRole !== null && $request->isMethodSafe()) {
            if (Auth::check()) {
                Auth::user()?->syncUiRoleSession();

                return $next($request);
            }

            if (UserManagementErdMode::isActive()) {
                $query = $request->query();
                unset($query['role']);

                return redirect()->to($this->publicUrl($request).(count($query) ? '?'.http_build_query($query) : ''));
            }

            UiRole::set($queryRole);

            $query = $request->query();
            unset($query['role']);

            return redirect()->to($this->publicUrl($request).(count($query) ? '?'.http_build_query($query) : ''));
        }

        if (Auth::check()) {
            Auth::user()?->syncUiRoleSession();
        }

        return $next($request);
    }

    /** The URL as the browser sent it (opaque codes), never the internally decoded one. */
    private function publicUrl(Request $request): string
    {
        $original = $request->attributes->get('opaque.original_uri');
        if (! is_string($original) || $original === '') {
            return $request->url();
        }

        return $request->getSchemeAndHttpHost().strtok($original, '?');
    }
}
