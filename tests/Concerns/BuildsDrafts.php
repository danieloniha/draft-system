<?php

namespace Tests\Concerns;

use App\Models\Draft;
use App\Models\Interest;
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
}
