<?php

namespace App\Http\Controllers\Offline;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\StaffRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OfflineStatusController extends Controller
{
    public function show(Request $request): JsonResponse
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
            'user_id' => $user->getKey(),
            'username' => (string) ($user->username ?? ''),
            'role' => StaffRole::normalize($user->role),
            'must_change_password' => (bool) $user->must_change_password,
            'is_active' => $user->isActive(),
            'csrf_token' => csrf_token(),
            'server_time' => now()->toIso8601String(),
            // Opaque per-authenticated-session id (session regenerates on login).
            // Used so fresh login refreshes offline datasets without tying to CSRF/passwords.
            'login_session_id' => hash('sha256', (string) $request->session()->getId()),
        ]);
    }
}
