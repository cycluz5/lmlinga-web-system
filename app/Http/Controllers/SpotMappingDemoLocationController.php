<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Geo\DemoLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * SR-10 Demo Location Mode toggle (route middleware `ui.admin`). Only on a gated deployment; only for the
 * signed-in Admin's own session; at most 2 hours. Turning it on never changes any record.
 */
class SpotMappingDemoLocationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless(DemoLocation::gateEnabled(), 404);
        $user = $request->user();
        abort_unless(DemoLocation::isActiveAdmin($user instanceof User ? $user : null), 403);

        $validated = $request->validate(['point' => ['required', 'string', 'max:40']]);

        try {
            $point = DemoLocation::enable($request->session(), $user, $validated['point']);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('spot-mapping.index')
                ->withErrors(['demo_location' => 'Demo Location Mode is unavailable: '.$e->getMessage()]);
        }

        Log::info('spot_mapping.demo_location.enabled', [
            'user_id' => $user->getKey(), 'point' => $point['key'], 'point_verified' => $point['verified'],
            'expires_at' => date(DATE_ATOM, $point['expires_at']),
        ]);

        return redirect()->route('spot-mapping.index')
            ->with('status', 'Demo Location Mode is on for your session only. Simulated coordinates are not saved.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        abort_unless(DemoLocation::gateEnabled() || DemoLocation::hasState($request->session()), 404);
        $user = $request->user();
        abort_unless(DemoLocation::isActiveAdmin($user instanceof User ? $user : null), 403);

        DemoLocation::disable($request->session());
        Log::info('spot_mapping.demo_location.disabled', ['user_id' => $user->getKey()]);

        return redirect()->route('spot-mapping.index')->with('status', 'Demo Location Mode is off. Real GPS is used again.');
    }
}
