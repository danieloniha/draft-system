<?php

namespace App\Services;

use App\Models\Draft;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one moment participation actually begins: a private invitee accepting
 * their token, or someone self-joining a public draft's link. Both paths need
 * the same small extra step under order_mode 'fcfs' — the participant order
 * is assigned the instant someone joins, not later by the host — so it lives
 * here once instead of being duplicated in two controller actions.
 */
class DraftJoinService
{
    /**
     * A private invitee accepts their invitation. $team is the row created
     * when the host invited them (see DraftEditor::addParticipants); the
     * controller has already decided $user may take it (their email matches,
     * or they are a guest holding the invitation link).
     *
     * Returns whether the seat is now $user's: true when this call took it or
     * they already had it (a duplicate submit is harmless), false when someone
     * else got there first.
     */
    public function joinPrivate(Team $team, User $user): bool
    {
        return DB::transaction(function () use ($team, $user) {
            $draft = Draft::whereKey($team->draft_id)->lockForUpdate()->firstOrFail();
            $team = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();

            if ($team->user_id !== null) {
                return (int) $team->user_id === (int) $user->id;
            }

            $team->update(['user_id' => $user->id]);
            $this->assignFcfsOrderIfNeeded($draft, $team);

            return true;
        });
    }

    /**
     * Someone follows a public draft's link and joins themself. Returns the
     * team row that represents them — their existing one, if they had already
     * joined, since joining twice is harmless, not an error.
     */
    public function joinPublic(Draft $draft, User $user): Team
    {
        return DB::transaction(function () use ($draft, $user) {
            $draft = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            abort_unless($draft->isPublic(), 404, 'This draft is not open for anyone to join.');
            abort_if($draft->hasStarted(), 409, 'This draft has already started.');

            $existing = $draft->teams()->where('user_id', $user->id)->first();
            if ($existing) {
                return $existing;
            }

            // The cap closes joining once reached; a removed participant frees a slot again.
            abort_if($draft->teams()->count() >= $draft->participant_limit, 409, 'This draft is full.');

            $team = Team::create([
                'draft_id' => $draft->id,
                'user_id' => $user->id,
                'email' => $user->email,
                'selection_no' => null,
                'token' => null,
            ]);

            $this->assignFcfsOrderIfNeeded($draft, $team);

            return $team;
        });
    }

    /**
     * order_mode 'fcfs': the moment someone joins (privately or publicly),
     * they take the next open place in the order. Giveaway only — Bidding's
     * order_mode governs item sequence instead (see DraftBiddingService), not
     * who joined when.
     */
    private function assignFcfsOrderIfNeeded(Draft $draft, Team $team): void
    {
        if ($draft->order_mode !== 'fcfs' || $draft->type !== 'giveaway') {
            return;
        }

        $next = (int) ($draft->teams()->max('selection_no') ?? 0) + 1;
        $team->update(['selection_no' => $next]);
    }
}
