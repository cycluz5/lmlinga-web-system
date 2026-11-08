<?php

namespace App\Http\Middleware;

use App\Support\OpaqueId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before routing: turns opaque codes in the path (and the Environmental Health
 * ?household= query) back into the internal ids, so routes and constraints stay unchanged.
 * Which path positions were decoded is recorded for EnsureOpaqueRouteParameters, which
 * rejects raw ids at any position that must be opaque.
 */
class DecodeOpaqueUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! OpaqueId::enabled()) {
            return $next($request);
        }

        $segments = explode('/', $request->getPathInfo());
        $decoded = [];
        $changed = false;

        foreach ($segments as $index => $segment) {
            if ($index === 0 || $segment === '') {
                continue;
            }
            $result = OpaqueId::decode(rawurldecode($segment));
            if ($result === null) {
                continue;
            }
            $segments[$index] = rawurlencode($result['value']);
            $decoded[$index - 1] = $result['kind'];
            $changed = true;
        }

        $queryDecoded = false;
        $query = $request->query->all();
        if (isset($query['household']) && is_string($query['household'])) {
            $value = OpaqueId::decodeKind('h', $query['household']);
            if ($value !== null) {
                $query['household'] = $value;
                $queryDecoded = true;
                $changed = true;
            }
        }

        if (! $changed) {
            return $next($request);
        }

        $server = $request->server->all();
        $queryString = $queryDecoded
            ? http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            : (string) $request->getQueryString();
        $server['REQUEST_URI'] = $request->getBaseUrl().implode('/', $segments)
            .($queryString !== '' ? '?'.$queryString : '');
        $server['QUERY_STRING'] = $queryString;

        $rewritten = $request->duplicate($queryDecoded ? $query : null, null, null, null, null, $server);
        $rewritten->attributes->set('opaque.segments', $decoded);
        $rewritten->attributes->set('opaque.original_uri', $request->getRequestUri());
        $rewritten->attributes->set('opaque.query_household', $queryDecoded);

        app()->instance('request', $rewritten);
        Facade::clearResolvedInstance('request');

        return $next($rewritten);
    }
}
