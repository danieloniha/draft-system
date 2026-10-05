<?php

namespace App\Services\Contracts;

use App\Models\Draft;

/**
 * What DraftController and DraftEditor need from "however this draft's items
 * get claimed" without caring whether that's Giveaway picking or Bidding.
 * Implemented by DraftPickService (giveaway) and DraftBiddingService (bidding).
 */
interface DraftFlowService
{
    /**
     * The live state the picking/bidding page and the state endpoint return.
     * Throws a 422 HttpException if the draft is not ready to show yet (someone
     * has not joined, or similar), and a 403 if it has not started when a rule
     * requires that it has.
     */
    public function state(Draft $draft): array;

    /**
     * The host starts the draft: assigns any automatic order (random), checks
     * everything required is in place, and begins the first turn/item's clock.
     */
    public function start(Draft $draft): void;

    /**
     * Settle the server's clock: if the current turn/item's time is up, resolve
     * it (skip the turn, or close the item) and move on. Called before every
     * read and every write, so a stale deadline is never trusted.
     */
    public function advanceClock(Draft $draft): mixed;

    /**
     * Tell everyone already on the page that something changed because the
     * host edited the draft. Best-effort: never throws.
     */
    public function announce(Draft $draft): void;
}
