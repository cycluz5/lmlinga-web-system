<?php

namespace App\Http\Middleware;

use App\Support\UiRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the UI shell role in session across authenticated dashboard navigation.
 * Optional ?role= seeds/updates the session on GET/HEAD only, then redirects
 * without the query so normal navigation never derives role from the URL.
 *
 * Apply only to dashboard-related route groups — not public/auth/chatbot pages.
 */
class PersistUiRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $queryRole = UiRole::normalize((string) $request->query('role', ''));

        if ($queryRole !== null && $request->isMethodSafe()) {
            UiRole::set($queryRole);

            $query = $request->query();
            unset($query['role']);

            return redirect()->to($request->url().(count($query) ? '?'.http_build_query($query) : ''));
        }

        return $next($request);
    }
}
