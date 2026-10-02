<?php

use App\Services\Enrichment\Drivers\BounceBanVerifier;
use App\Services\Enrichment\Drivers\FindymailFinder;
use App\Services\Enrichment\Drivers\HunterFinder;
use App\Services\Enrichment\Drivers\MyEmailVerifierVerifier;
use App\Services\Enrichment\Drivers\ReoonVerifier;
use App\Services\Enrichment\Drivers\ZeroBounceVerifier;

return [

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Every provider the system can talk to. Each appears in the panel by
    | itself and does nothing until a key is put against it.
    |
    | What belongs in code is what is true of the provider: how to call it, what
    | it charges, whether it finds addresses or checks them. What belongs in the
    | database is what is true of our account with it: the key, the order it is
    | tried in, and whether it is switched on.
    |
    | Adding a provider is a class and a line here. Its name on screen comes
    | from a static label() on the class.
    |
    */

    'drivers' => [
        'hunter' => HunterFinder::class,
        'findymail' => FindymailFinder::class,
        'reoon' => ReoonVerifier::class,
        'zerobounce' => ZeroBounceVerifier::class,
        'myemailverifier' => MyEmailVerifierVerifier::class,
        'bounceban' => BounceBanVerifier::class,
    ],

    /*
     * How long to wait on a provider before giving up on it.
     *
     * Short on purpose: a lookup is one step of a queued job, and a provider
     * that has stopped answering should fall through to the next one rather
     * than hold the queue.
     */
    'timeout' => (int) env('ENRICHMENT_TIMEOUT', 20),

    /*
     * How long to wait on a provider that is being asked for its balance.
     *
     * Tighter than the lookup timeout, because somebody is standing in front
     * of it. One press of "Check credits now" asks every provider that has a
     * key, one after another, so a generous timeout on a provider that has
     * stopped answering is paid by whoever pressed it.
     */
    'balance_timeout' => (int) env('ENRICHMENT_BALANCE_TIMEOUT', 8),

    /*
     * Leads that are still waiting for an address or a check are sent through
     * again by `enrichment:retry`, every fifteen minutes.
     *
     * A limited batch, spaced out, rather than everybody at once. Lookups
     * share the queue with drafting and sending, and two thousand of them
     * queued together would hold every send up behind them. 200 leads four
     * seconds apart takes about thirteen minutes, so one run is finished
     * before the next begins.
     *
     * Leads marked "waiting to retry" are picked up at once. A lead still at
     * pending, finding or verifying after stale_minutes is one whose lookup
     * never ran or died part way, a worker killed during a deploy, say, since
     * a lookup takes seconds; those are picked up too.
     */
    'retry' => [
        'batch' => (int) env('ENRICHMENT_RETRY_BATCH', 200),
        'spacing_seconds' => (int) env('ENRICHMENT_RETRY_SPACING', 4),
        'stale_minutes' => (int) env('ENRICHMENT_RETRY_STALE_MINUTES', 15),
    ],

    /*
     * Who is told when a finder or checker runs low or is switched off, and
     * what counts as low.
     *
     * Comma separated. Left empty, every user of the dashboard is told.
     */
    'alerts' => [
        'to' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENRICHMENT_ALERT_EMAIL', ''))))),
        'low_credits' => (int) env('ENRICHMENT_LOW_CREDITS', 50),
    ],

];
