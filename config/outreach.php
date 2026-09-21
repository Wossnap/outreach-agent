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
    | Open tracking
    |--------------------------------------------------------------------------
    | On, every email carries an HTML part with a 1x1 image served from this
    | app (APP_URL must be the public host). Off, emails go out as plain text
    | exactly as before. Opens are directional: image blocking undercounts,
    | Gmail's image proxy and Apple Mail Privacy Protection overcount.
    |
    | A hit this soon after sending is a link scanner or a prefetch, not a
    | person, and is not recorded.
    */
    'open_tracking' => [
        'enabled' => (bool) env('OUTREACH_OPEN_TRACKING', true),
        'ignore_seconds_after_send' => (int) env('OUTREACH_OPEN_TRACKING_IGNORE_SECONDS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Postmaster Tools
    |--------------------------------------------------------------------------
    | Gmail's own spam-rate figures per sending domain, pulled daily. A domain
    | only reports once it is verified at postmaster.google.com by the same
    | Google account as a connected mailbox, and only for days with enough
    | Gmail volume. The first sync asks for lookback_days; later runs re-ask
    | for the last week, because Postmaster lags by a day or three.
    */
    'postmaster' => [
        'enabled' => (bool) env('OUTREACH_POSTMASTER_SYNC', true),
        'lookback_days' => (int) env('OUTREACH_POSTMASTER_LOOKBACK_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | API surface
    |--------------------------------------------------------------------------
    | Two actions are withheld from API keys by default because they remove a
    | safeguard rather than just moving data:
    |
    | allow_approval - a key may approve a draft, which sends a real email with
    | no human ever reading it. Every email otherwise passes a person first.
    |
    | allow_suppression_removal - a key may take someone off the opt-out list,
    | i.e. resume emailing a person who asked us to stop.
    |
    | Turn either on deliberately. Both routes 403 while off, whatever
    | abilities the key carries.
    */
    'api' => [
        'allow_approval' => (bool) env('OUTREACH_API_ALLOW_APPROVAL', false),
        'allow_suppression_removal' => (bool) env('OUTREACH_API_ALLOW_SUPPRESSION_REMOVAL', false),
        'page_size' => (int) env('OUTREACH_API_PAGE_SIZE', 50),
        'max_page_size' => (int) env('OUTREACH_API_MAX_PAGE_SIZE', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Step attachments
    |--------------------------------------------------------------------------
    | Files attached to a sequence step travel base64-encoded inside the
    | message, so the encoded size is roughly a third larger than the file.
    | Gmail rejects a message over 35MB total.
    */
    'attachments' => [
        'disk' => env('OUTREACH_ATTACHMENT_DISK', 'local'),
        'max_size_kb' => (int) env('OUTREACH_ATTACHMENT_MAX_KB', 10240),
        'max_per_step' => (int) env('OUTREACH_ATTACHMENT_MAX_PER_STEP', 5),
        // Executables and archives are excluded: recipients' gateways strip or
        // quarantine them, which costs deliverability on the whole domain.
        'allowed_extensions' => [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'csv', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp',
        ],
    ],

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
