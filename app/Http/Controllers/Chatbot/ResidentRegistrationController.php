<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\ResidentAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ResidentRegistrationController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'zone' => ['required', 'in:1,2,3,4,5'],
            'email' => ['required', 'email', 'max:255', 'unique:resident_accounts,email'],
            'password' => [
                'required',
                'string',
                Password::min(8)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised(),
            ],
        ]);

        ResidentAccount::create([
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'],
            'last_name' => $validated['last_name'],
            'zone_purok' => $validated['zone'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()
            ->route('chatbot.login')
            ->with('success', 'Account created successfully. You can now log in.');
    }
}