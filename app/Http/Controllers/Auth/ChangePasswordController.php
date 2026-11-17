<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\StrongPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ChangePasswordController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        return view('pages.auth.change-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'new_password' => StrongPassword::laravelRules(true),
        ], [
            'new_password.required' => 'New Password is required.',
            'new_password.confirmed' => 'Password and Confirm New Password must match.',
        ]);

        $changes = [
            'password' => $validated['new_password'],
            'must_change_password' => false,
        ];
        // Replacing an admin-issued temporary password also ends the first-login requirement.
        if (Schema::hasColumn($user->getTable(), 'temp_password_issued_at')) {
            $changes['temp_password_issued_at'] = null;
        }

        $user->forceFill($changes)->save();

        return redirect()
            ->route('profile.show')
            ->with('status', 'Your password has been updated.');
    }
}
