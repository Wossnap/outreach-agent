<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display / default sending timezone
    |--------------------------------------------------------------------------
    | All timestamps are stored UTC. This timezone is used for display in the
    | dashboard and as the default per-mailbox sending window timezone.
    */
    'timezone' => env('OUTREACH_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Sign-up
    |--------------------------------------------------------------------------
    | An account on this dashboard can create API keys and approve emails that
    | send from the connected mailboxes, so registration is closed by default.
    | Turn it on long enough to create an account, then turn it off again.
    */
    'registration_enabled' => (bool) env('OUTREACH_REGISTRATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Sending defaults (per mailbox, overridable per mailbox row)
    |--------------------------------------------------------------------------
    | Gaps are second-granular at runtime: a send slot is placed a random
    | number of seconds between min_gap and max_gap after the previous slot,
    | so sends never align to cron minute boundaries.
    */
    'daily_cap_default' => (int) env('OUTREACH_DAILY_CAP_DEFAULT', 40),
    'min_gap_minutes' => (int) env('OUTREACH_MIN_GAP_MINUTES', 3),
    'max_gap_minutes' => (int) env('OUTREACH_MAX_GAP_MINUTES', 15),
    'send_window_start' => env('OUTREACH_SEND_WINDOW_START', '09:00'),
    'send_window_end' => env('OUTREACH_SEND_WINDOW_END', '17:00'),
    'send_weekends' => (bool) env('OUTREACH_SEND_WEEKENDS', false),

    /*
    |--------------------------------------------------------------------------
    | Warmup ramp
    |--------------------------------------------------------------------------
    | A warming mailbox's effective daily cap starts at warmup_start_per_day
    | and grows by warmup_increment_per_day each day until it reaches the
    | mailbox daily_cap.
    */
    'warmup_start_per_day' => (int) env('OUTREACH_WARMUP_START', 5),
    'warmup_increment_per_day' => (int) env('OUTREACH_WARMUP_INCREMENT', 3),

    /*
    |--------------------------------------------------------------------------
    | Health thresholds (auto-pause)
    |--------------------------------------------------------------------------
    */
    'bounce_rate_pause_threshold' => (float) env('OUTREACH_BOUNCE_PAUSE_THRESHOLD', 0.05),
    'bounce_rate_min_sends' => (int) env('OUTREACH_BOUNCE_MIN_SENDS', 20),

    /*
    |--------------------------------------------------------------------------
    | Deliverability
    |--------------------------------------------------------------------------
    | Note: sending goes through the Gmail API, so mail leaves Google's IPs —
    | IP reputation is Google's. We monitor what we own: domain DNS auth
    | (SPF/DKIM/DMARC), domain blocklists, and engagement (bounce/reply rates).
    */
    'dkim_default_selector' => env('OUTREACH_DKIM_SELECTOR', 'google'),
    'dnsbl_zones' => [
        'dbl.spamhaus.org',
        'multi.surbl.org',
        'multi.uribl.com',
    ],
    'list_unsubscribe_header' => (bool) env('OUTREACH_LIST_UNSUBSCRIBE', true),

    /*
    |--------------------------------------------------------------------------
    | Unsubscribe phrase detection (inbound replies)
    |--------------------------------------------------------------------------
    | A reply whose body/subject contains one of these (case-insensitive) is
    | treated as an opt-out: contact suppressed, enrollment stopped.
    | "not interested" is deliberately NOT here — that's a reply, not opt-out.
    */
    'unsubscribe_phrases' => [
        'unsubscribe',
        'remove me',
        'stop emailing',
        'stop contacting',
        'opt out',
        'opt-out',
        'take me off',
        'do not email',
        "don't email me",
    ],
];
