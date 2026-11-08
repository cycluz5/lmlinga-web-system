<?php

namespace App\Http\Middleware;

use App\Support\Geo\DemoLocation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SR-10: while Demo Location Mode state exists in this session (even if it has just expired), Spot Mapping
 * coordinate writes are refused before validation or any database access, so a simulated coordinate can
 * never be saved as a real household measurement. The state is cleared only by "Turn off Demo Mode",
 * reloading Spot Mapping after expiry, or logout. Existing validation is untouched for real saves.
 */
class EnsureNotInDemoLocation
{
    public const MESSAGE = 'Demo Location Mode is on: simulated coordinates are not saved. Turn off Demo Mode to plot real GPS locations.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && DemoLocation::hasState($request->session())) {
            return response()->json(['message' => self::MESSAGE, 'demo_location' => true], 409);
        }

        return $next($request);
    }
}
