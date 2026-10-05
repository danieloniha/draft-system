<?php

namespace Tests\Concerns;

use App\Models\Draft;
use App\Models\Interest;
use App\Models\PayoutTier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

trait BuildsDrafts
{
    /**
     * A draft that is ready to pick from: every participant has joined and has
     * a place in the order.
     *
     * @param  array<string, mixed>  $attributes  Overrides for the draft itself.
     * @return array{0: Draft, 1: Collection<int, User>, 2: Collection<int, Interest>} The draft,
     *         its participants in selection order, and its items.
     */
    private function buildDraft(int $players = 3, int $items = 4, array $attributes = []): array
    {
        $draft = Draft::factory()->create($attributes);

        $participants = collect(range(1, $players))->map(function (int $selectionNo) use ($draft) {
            $user = User::factory()->create();
            Team::factory()->create([
                'draft_id' => $draft->id,
                'user_id' => $user->id,
                'email' => $user->email,
                'selection_no' => $selectionNo,
            ]);

            return $user;
        });

        $interests = Interest::factory()->count($items)->create(['draft_id' => $draft->id]);

        return [$draft, $participants, $interests];
    }

    /**
     * A Bidding draft ready to run: every participant has joined, and every
     * item has its place in the auction sequence.
     *
     * @param  array<string, mixed>  $attributes  Overrides for the draft itself.
     * @return array{0: Draft, 1: Collection<int, User>, 2: Collection<int, Interest>} The draft,
     *         its participants, and its items in auction order.
     */
    private function buildBiddingDraft(int $players = 3, int $items = 4, array $attributes = []): array
    {
        $draft = Draft::factory()->create(['type' => 'bidding', ...$attributes]);

        $participants = collect(range(1, $players))->map(function () use ($draft) {
            $user = User::factory()->create();
            Team::factory()->create([
                'draft_id' => $draft->id,
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return $user;
        });

        // range(1, 0) descends to [1, 0] rather than being empty, so guard $items = 0 explicitly.
        $interests = collect($items > 0 ? range(1, $items) : [])->map(
            fn (int $position) => Interest::factory()->create(['draft_id' => $draft->id, 'position' => $position])
        );

        return [$draft, $participants, $interests];
    }

    /**
     * A Giveaway draft in money mode, ready to reveal: every participant has
     * joined and has a rank, and its payout table is set up (one tier
     * covering every rank, by default).
     *
     * @param  array<string, mixed>  $attributes  Overrides for the draft itself.
     * @param  ?list<array{rank_from: int, rank_to: int, amount: int}>  $tiers  Overrides for the payout table.
     * @return array{0: Draft, 1: Collection<int, User>, 2: Collection<int, PayoutTier>} The draft,
     *         its participants in rank order, and its payout tiers.
     */
    private function buildMoneyDraft(int $players = 3, ?array $tiers = null, array $attributes = []): array
    {
        $draft = Draft::factory()->create(['type' => 'giveaway', 'giveaway_mode' => 'money', ...$attributes]);

        // range(1, 0) descends to [1, 0] rather than being empty, so guard $players = 0 explicitly.
        $participants = collect($players > 0 ? range(1, $players) : [])->map(function (int $selectionNo) use ($draft) {
            $user = User::factory()->create();
            Team::factory()->create([
                'draft_id' => $draft->id,
                'user_id' => $user->id,
                'email' => $user->email,
                'selection_no' => $selectionNo,
            ]);

            return $user;
        });

        $tiers ??= [['rank_from' => 1, 'rank_to' => max($players, 1), 'amount' => 1000]];
        $payoutTiers = collect($tiers)->map(
            fn (array $tier) => PayoutTier::create(['draft_id' => $draft->id, ...$tier])
        );

        return [$draft, $participants, $payoutTiers];
    }
}
