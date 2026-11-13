<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\ResidentAccount;
use App\Services\ResidentEmailOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ResidentRegistrationController extends Controller
{
    /**
     * New resident registrations accept Gmail addresses only (strict Gmail username rules:
     * 6–30 characters, letters/digits/dots, no leading, trailing or consecutive dots, no
     * "+alias"). Checked after trimming and lowercasing. Must match resources/js/pages/chatbot-register.js.
     * Gmail ignores dots, so "j.uan@gmail.com" and "juan@gmail.com" are not treated as duplicates (known limitation).
     */
    public const GMAIL_PATTERN = '/^(?=[a-z0-9.]{6,30}@)(?!.*\.\.)[a-z0-9](?:[a-z0-9.]*[a-z0-9])?@gmail\.com$/';

    public const GMAIL_MESSAGE = 'Please use a Gmail address ending in @gmail.com to register.';

    public function store(Request $request, ResidentEmailOtpService $emailOtp)
    {
        $this->normalizeEmail($request);

        if (! ResidentEmailOtpService::enabled()) {
            return $this->storeWithoutEmailVerification($request);
        }

        // No uniqueness rule here: the response must not reveal whether an email is registered.
        // A taken address gets a notice email instead of a code and can never create a second account.
        $validated = $request->validate($this->rules(unique: false), $this->messages());

        $result = $emailOtp->startRegistration($validated);

        if ($result['outcome'] !== ResidentEmailOtpService::SENT) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors(['email' => $result['outcome'] === ResidentEmailOtpService::ADDRESS_LIMIT
                    ? 'Too many verification emails have been requested. Please try again later.'
                    : 'We could not send the verification email. Please try again.']);
        }

        $previous = $request->session()->get(ResidentEmailOtpService::SESSION_PENDING_TOKEN);
        $emailOtp->forgetPending(is_string($previous) ? $previous : null);
        $request->session()->put(ResidentEmailOtpService::SESSION_PENDING_TOKEN, $result['token']);

        return redirect()->route('chatbot.register.verify');
    }

    /** Original behaviour, kept for rollback (lmlinga.resident_email_verification.enabled = false). */
    private function storeWithoutEmailVerification(Request $request)
    {
        $validated = $request->validate($this->rules(unique: true), $this->messages());

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

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $unique): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'zone' => ['required', 'in:1,2,3,4,5'],
            'email' => array_merge(
                ['required', 'string', 'max:255', 'email', 'regex:'.self::GMAIL_PATTERN],
                $unique ? ['unique:resident_accounts,email'] : []
            ),
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
        ];
    }

    /**
     * Any invalid address gets the same Gmail message; an empty field keeps the standard "required" message.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'email.string' => self::GMAIL_MESSAGE,
            'email.max' => self::GMAIL_MESSAGE,
            'email.email' => self::GMAIL_MESSAGE,
            'email.regex' => self::GMAIL_MESSAGE,
        ];
    }

    /** Trim and lowercase before validation, so "  Juan.Dela@Gmail.com " is checked and stored as "juan.dela@gmail.com". */
    private function normalizeEmail(Request $request): void
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge(['email' => mb_strtolower(trim($email), 'UTF-8')]);
        }
    }
}
