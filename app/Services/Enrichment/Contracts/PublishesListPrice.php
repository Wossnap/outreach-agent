<?php

namespace App\Services\Enrichment\Contracts;

use App\Services\Enrichment\ListPrice;

/**
 * A driver that knows what its provider charges.
 *
 * Optional, because a provider whose pricing nobody has checked should say
 * nothing rather than guess. The cost-per-answer figure on the Spend page is
 * built on this, and a wrong figure there still reads as an answer.
 */
interface PublishesListPrice
{
    public static function listPrice(): ListPrice;
}
