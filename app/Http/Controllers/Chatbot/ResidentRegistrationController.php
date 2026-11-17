<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\ResidentAccount;
use App\Services\ResidentRegistrationOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ResidentRegistrationController extends Controller
{
    public function __construct(private readonly ResidentRegistrationOtpService $otp) {}

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'zone' => ['required', 'in:1,2,3,4,5'],
            'email' => ['required', 'email', 'max:150', 'unique:resident_accounts,email'],
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

        if (! ResidentRegistrationOtpService::enabled()) {
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

        $this->otp->start([
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'],
            'last_name' => $validated['last_name'],
            'zone' => $validated['zone'],
            'email' => $validated['email'],
            'password_hash' => Hash::make($validated['password']),
        ]);

        $result = $this->otp->sendCode($validated['email']);

        if ($result === ResidentRegistrationOtpService::DELIVERY_FAILED) {
            $this->otp->discard($validated['email']);

            return back()
                ->withInput($request->except('password'))
                ->withErrors(['email' => 'We could not send a verification code to this email. Check the address and try again.']);
        }

        $this->otp->bindSession($validated['email']);

        $redirect = redirect()->route('chatbot.register.verify');

        return match ($result) {
            ResidentRegistrationOtpService::SENT => $redirect->with('status', 'We sent a 6-digit code to your email.'),
            ResidentRegistrationOtpService::COOLDOWN => $redirect->with('status', 'A code was sent a moment ago. Please wait before requesting another.'),
            default => $redirect->withErrors(['otp' => 'Too many codes were requested for this email. Please try again later.']),
        };
    }

    public function showVerify(): View|RedirectResponse
    {
        $email = $this->otp->sessionEmail();
        if ($email === null) {
            return redirect()->route('chatbot.register');
        }

        return view('pages.chatbot.register-verify', [
            'maskedEmail' => ResidentRegistrationOtpService::maskEmail($email),
            'expiryMinutes' => ResidentRegistrationOtpService::EXPIRY_MINUTES,
            'secondsRemaining' => $this->otp->secondsRemaining($email),
            'cooldownRemaining' => $this->otp->cooldownRemaining($email),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $email = $this->otp->sessionEmail();
        if ($email === null) {
            return redirect()->route('chatbot.register');
        }

        $validated = $request->validate([
            'otp' => ['required', 'string', 'max:12'],
        ]);

        $result = $this->otp->verify($email, $validated['otp']);

        switch ($result) {
            case ResidentRegistrationOtpService::VERIFIED:
                $this->otp->forgetSession();

                return redirect()
                    ->route('chatbot.login')
                    ->with('success', 'Email verified, account created. You can now log in.');

            case ResidentRegistrationOtpService::EMAIL_TAKEN:
                $this->otp->forgetSession();

                return redirect()
                    ->route('chatbot.register')
                    ->withErrors(['email' => 'This email is already registered. Please log in instead.']);

            case ResidentRegistrationOtpService::NOT_PENDING:
                $this->otp->forgetSession();

                return redirect()->route('chatbot.register');

            case ResidentRegistrationOtpService::EXHAUSTED:
                return back()->withErrors(['otp' => 'Too many incorrect attempts. Request a new code.']);

            default:
                return back()->withErrors(['otp' => 'That code is incorrect or has expired.']);
        }
    }

    public function resend(): RedirectResponse
    {
        $email = $this->otp->sessionEmail();
        if ($email === null) {
            return redirect()->route('chatbot.register');
        }

        return match ($this->otp->sendCode($email)) {
            ResidentRegistrationOtpService::SENT => back()->with('status', 'A new code was sent to your email.'),
            ResidentRegistrationOtpService::COOLDOWN => back()->withErrors(['otp' => 'Please wait a minute before requesting another code.']),
            ResidentRegistrationOtpService::SEND_LIMIT => back()->withErrors(['otp' => 'Too many codes were requested for this email. Please try again later.']),
            ResidentRegistrationOtpService::DELIVERY_FAILED => back()->withErrors(['otp' => 'We could not send the code. Please try again.']),
            default => redirect()->route('chatbot.register'),
        };
    }

    public function cancel(): RedirectResponse
    {
        $email = $this->otp->sessionEmail();
        if ($email !== null) {
            $this->otp->discard($email);
        }
        $this->otp->forgetSession();

        return redirect()->route('chatbot.register');
    }
}
