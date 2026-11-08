<?php

namespace App\Http\Controllers\Offline;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Offline\OfflineHouseholdProfilingBootstrap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OfflineHouseholdProfilingBootstrapController extends Controller
{
    public function show(Request $request, OfflineHouseholdProfilingBootstrap $bootstrap): JsonResponse
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return response()->json([
                'ok' => false,
                'code' => 'SESSION_EXPIRED',
                'message' => 'Your session has expired. Please sign in again.',
            ], 401);
        }

        return response()->json([
            'ok' => true,
            'actor_id' => $user->getKey(),
            'payload' => $bootstrap->payload(),
        ]);
    }
}
