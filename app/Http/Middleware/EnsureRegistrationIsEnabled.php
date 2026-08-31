<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the sign-up page unless registration is deliberately switched on.
 *
 * This dashboard controls real mailboxes: an account on it can create API keys
 * and approve emails that send from them. Registration therefore defaults to
 * off, and is turned on only long enough to create an account.
 *
 * Controlled by OUTREACH_REGISTRATION_ENABLED.
 */
class EnsureRegistrationIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('outreach.registration_enabled'), 404);

        return $next($request);
    }
}
