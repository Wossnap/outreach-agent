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

];
