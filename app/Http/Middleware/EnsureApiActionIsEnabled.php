<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks an API action that is switched off in config, whatever abilities the
 * key carries.
 *
 * Two actions are withheld by default because they remove a safeguard rather
 * than just moving data: approving a draft (sends real email with no human
 * reading it) and un-suppressing an address (resumes emailing someone who
 * asked us to stop). See config/outreach.php.
 */
class EnsureApiActionIsEnabled
{
    public function handle(Request $request, Closure $next, string $setting): Response
    {
        if (! config('outreach.api.'.$setting)) {
            return response()->json([
                'success' => false,
                'message' => 'This action is disabled. An administrator can enable it with '
                    .'OUTREACH_API_'.mb_strtoupper($setting).'=true.',
            ], 403);
        }

        return $next($request);
    }
}
