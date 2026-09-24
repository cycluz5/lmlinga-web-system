<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Prevents authenticated dashboard HTML from being reused from browser cache
 * or BFCache after logout. JSON/offline/file responses are left unchanged.
 */
class PreventAuthenticatedPageCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldApply($request, $response)) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    private function shouldApply(Request $request, Response $response): bool
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return false;
        }

        if ($request->is('offline') || $request->is('offline/*')) {
            return false;
        }

        if ($request->expectsJson() || $request->isJson()) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && ! str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }

        return true;
    }
}
