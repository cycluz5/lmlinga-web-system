<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Chatbot\ResidentEmailVerificationController;
use App\Models\ResidentAccount;
use App\Services\ResidentEmailOtpService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a verified email before a resident submits a new household record request (SR-03).
 * Runs after resident.chatbot. Viewing pages, the chatbot, existing household links and requests
 * already in progress are not affected. Passes through before the email_verified_at migration has
 * run and when the feature is disabled, so existing accounts are never locked out.
 */
class EnsureResidentEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->attributes->get('residentAccount');

        if (
            ! $account instanceof ResidentAccount
            || ! ResidentEmailOtpService::enabled()
            || ! ResidentAccount::supportsEmailVerification()
            || $account->hasVerifiedEmail()
        ) {
            return $next($request);
        }

        $request->session()->put(ResidentEmailVerificationController::SESSION_RETURN_TO_REQUEST, true);

        return redirect()
            ->route('chatbot.email.verify')
            ->with('email_verification_required', 'Please verify your email address before submitting a household record request.');
    }
}
