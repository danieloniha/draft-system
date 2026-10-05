<?php

namespace App\Services\Support;

use App\Models\Draft;
use Illuminate\Support\Carbon;

/**
 * The turn clock both flow services share: `drafts.turn_started_at` marks when
 * the *current* turn (Giveaway) or item window (Bidding) began, and each lasts
 * `selection_time_limit` seconds. Identical logic in both services, so it lives
 * here once rather than drifting apart.
 */
class DraftClock
{
    public function deadline(Draft $draft): Carbon
    {
        return $draft->turn_started_at->copy()->addSeconds((int) $draft->selection_time_limit);
    }

    /**
     * True when the draft is running and the current turn/item's time is up.
     */
    public function hasExpired(Draft $draft): bool
    {
        return $draft->turn_started_at !== null && $this->deadline($draft)->lte(now());
    }
}
