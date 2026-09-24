<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Offline endpoints are JSON-only. Force Accept so guests/CSRF/password
 * failures return JSON instead of HTML redirects or Blade error pages.
 */
class EnsureOfflineJsonRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('offline') || $request->is('offline/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
