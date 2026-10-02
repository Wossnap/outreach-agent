<?php

namespace App\Services\Enrichment\Contracts;

/**
 * A verifier that can say whether a mailbox exists on a catch-all domain.
 *
 * Most verifiers answer "catch-all" and stop, because such a domain accepts
 * every address it is offered. When a finder has already said the domain is
 * catch-all, asking one of those again pays for the same answer twice, so an
 * address like that goes only to verifiers marked with this.
 */
interface ResolvesCatchAll {}
