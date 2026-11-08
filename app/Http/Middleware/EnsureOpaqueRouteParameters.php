<?php

namespace App\Http\Middleware;

use App\Support\OpaqueId;
use App\Support\OpaqueUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * A raw record id (001, MB-041, 24, 2 ...) in a place that must be opaque is a 404,
 * so record numbers cannot be enumerated through the URL.
 */
class EnsureOpaqueRouteParameters
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if (! OpaqueId::enabled() || ! $route instanceof Route) {
            return $next($request);
        }

        $decoded = (array) $request->attributes->get('opaque.segments', []);
        $uri = $route->uri();

        foreach (explode('/', $uri) as $index => $segment) {
            if (preg_match('/^\{(\w+)\??\}$/', $segment, $match) !== 1) {
                continue;
            }
            $kind = OpaqueUrl::kind($match[1], $uri);
            if ($kind !== null && ($decoded[$index] ?? null) !== $kind) {
                abort(404);
            }
        }

        if ($uri === OpaqueUrl::EH_QUERY_ROUTE && $request->query->has('household')
            && ! $request->attributes->get('opaque.query_household', false)) {
            abort(404);
        }

        return $next($request);
    }
}
