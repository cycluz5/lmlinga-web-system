<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\HealthWorkerUiCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WorkerProfileController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        return view('pages.profile.show', [
            'active' => 'profile',
            'pageTitle' => 'User Profile',
            'pageSubtitle' => 'Your account details and employment assignment.',
            'profile' => HealthWorkerUiCatalog::presentUser($user),
        ]);
    }
}
