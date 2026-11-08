<?php

namespace App\Http\Middleware;

use App\Models\ResidentAccount;
use App\Support\ResidentAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate chatbot resident routes using the target resident session contract.
 * Does not use the staff web guard.
 */
class EnsureResidentChatbotAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $request->session()->get(ResidentAuthenticator::SESSION_ACCOUNT_ID);

        if ($accountId === null || $accountId === '') {
            return redirect()->route('chatbot.login');
        }

        $account = ResidentAccount::query()->find($accountId);

        if ($account === null) {
            ResidentAuthenticator::clearSession();

            return redirect()->route('chatbot.login');
        }

        $request->attributes->set('residentAccount', $account);

        return $next($request);
    }
}
