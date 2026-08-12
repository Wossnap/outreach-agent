<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Services\Gmail\GmailClientFactory;
use Google\Service\Oauth2;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GoogleOAuthController extends Controller
{
    public function redirect(GmailClientFactory $factory): RedirectResponse
    {
        return redirect()->away($factory->bare()->createAuthUrl());
    }

    public function callback(Request $request, GmailClientFactory $factory): RedirectResponse
    {
        if ($request->has('error') || ! $request->has('code')) {
            return redirect()->route('mailboxes.index')
                ->with('error', 'Google connection was cancelled or failed: '.$request->query('error', 'no code returned'));
        }

        $client = $factory->bare();
        $token = $client->fetchAccessTokenWithAuthCode($request->query('code'));

        if (isset($token['error'])) {
            return redirect()->route('mailboxes.index')
                ->with('error', 'Google token exchange failed: '.($token['error_description'] ?? $token['error']));
        }

        $client->setAccessToken($token);
        $email = mb_strtolower((new Oauth2($client))->userinfo->get()->getEmail());

        $domain = Domain::query()->firstOrCreate(['name' => Str::after($email, '@')]);

        $mailbox = Mailbox::query()->firstOrNew(['email' => $email]);
        $isNew = ! $mailbox->exists;

        $mailbox->fill([
            'domain_id' => $domain->id,
            'google_access_token' => $token['access_token'],
            'google_token_expires_at' => now()->addSeconds($token['expires_in'] ?? 3600),
            'google_scopes' => explode(' ', $token['scope'] ?? ''),
            'status' => Mailbox::STATUS_ACTIVE,
            'paused_reason' => null,
        ]);

        // Google only returns a refresh token on the first consent (or after
        // prompt=consent); never overwrite a stored one with null.
        if (! empty($token['refresh_token'])) {
            $mailbox->google_refresh_token = $token['refresh_token'];
        }

        if ($isNew) {
            $mailbox->fill([
                'display_name' => Str::headline(Str::before($email, '@')),
                'daily_cap' => config('outreach.daily_cap_default'),
                'min_gap_minutes' => config('outreach.min_gap_minutes'),
                'max_gap_minutes' => config('outreach.max_gap_minutes'),
                'send_window_start' => config('outreach.send_window_start'),
                'send_window_end' => config('outreach.send_window_end'),
                'send_timezone' => config('outreach.timezone'),
                'send_weekends' => config('outreach.send_weekends'),
                'warmup_enabled' => true,
                'warmup_started_at' => now(),
                'warmup_start_per_day' => config('outreach.warmup_start_per_day'),
                'warmup_increment_per_day' => config('outreach.warmup_increment_per_day'),
            ]);
        }

        $mailbox->save();

        return redirect()->route('mailboxes.index')
            ->with('status', ($isNew ? 'Connected ' : 'Reconnected ').$email.($isNew ? ' — warmup started at '.$mailbox->warmup_start_per_day.'/day.' : '.'));
    }
}
