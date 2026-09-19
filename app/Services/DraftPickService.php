<?php

namespace App\Services;

use App\Events\DraftStateChanged;
use App\Models\Draft;
use App\Models\Interest;
use App\Models\Selection;
use App\Models\Team;
use App\Models\TurnSkip;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class DraftPickService
{
    /**
     * The state every participant sees: who is in the order, whose turn it is,
     * how long they have and which items are gone.
     *
     * Clients treat "version" as a counter that only goes up and ignore any
     * snapshot older than the one they already hold. "server_time" lets them
     * count down against the server's clock instead of their own.
     */
    public function state(Draft $draft): array
    {
        $draft = Draft::findOrFail($draft->id);
        $teams = $this->teams($draft);
        $this->assertReady($teams);

        $selections = Selection::where('draft_id', $draft->id)->orderBy('id')->get();
        $skips = TurnSkip::where('draft_id', $draft->id)->orderBy('turn_number')->get();

        $pickCount = $selections->count();
        $turnNumber = $pickCount + $skips->count();
        $isComplete = $pickCount >= $draft->interests()->count();
        $isStarted = $draft->turn_started_at !== null;

        // Skipped turns do not use up items, so only picks can complete a draft.
        // Until the host starts it, a draft is 'scheduled' before its start time and 'waiting' after.
        $status = match (true) {
            $isComplete => 'complete',
            $isStarted => 'in_progress',
            $draft->start_date->gt(now()) => 'scheduled',
            default => 'waiting',
        };

        $playersByTeam = $teams->mapWithKeys(fn ($team) => [$team->id => [
            'id' => (int) $team->user_id,
            'username' => $team->user->username,
            'selection_no' => (int) $team->selection_no,
        ]]);

        $currentTeam = $status === 'in_progress' ? $teams[$turnNumber % $teams->count()] : null;
        $lastPick = $selections->last();
        $lastSkip = $skips->last();

        return [
            'draft_id' => $draft->id,
            'status' => $status,
            'version' => $turnNumber + ($isStarted ? 1 : 0),
            'pick_count' => $pickCount,
            'turn_number' => $turnNumber,
            'time_limit' => (int) $draft->selection_time_limit,
            'starts_at' => $draft->start_date->toIso8601String(),
            'turn_ends_at' => $status === 'in_progress' ? $this->deadline($draft)->toIso8601String() : null,
            'server_time' => now()->toIso8601String(),
            'current_player' => $currentTeam ? $playersByTeam[$currentTeam->id] : null,
            'players' => $playersByTeam->values()->all(),
            'layout' => $this->layout($draft, $teams),
            'selected_interest_ids' => $selections->pluck('interest_id')->map(fn ($id) => (int) $id)->all(),
            'last_pick' => $lastPick ? [
                'interest_id' => (int) $lastPick->interest_id,
                'interest_name' => $lastPick->selected,
                'player_id' => $playersByTeam[$lastPick->team_id]['id'],
                'player_username' => $playersByTeam[$lastPick->team_id]['username'],
            ] : null,
            // Only reported while the skip is the most recent thing that happened.
            'last_skip' => $lastSkip && (int) $lastSkip->turn_number === $turnNumber - 1 ? [
                'player_id' => $playersByTeam[$lastSkip->team_id]['id'],
                'player_username' => $playersByTeam[$lastSkip->team_id]['username'],
            ] : null,
        ];
    }

    /**
     * Tell everyone on the picking page that something about the draft changed
     * (the host edited it). A draft that cannot show a state yet, because someone
     * has not joined or there is no order, has nothing to announce.
     *
     * This runs after the change is committed and is best-effort: it never
     * throws, so a failure here cannot make the caller undo work that is saved.
     */
    public function announce(Draft $draft): void
    {
        try {
            $this->broadcast($this->state($draft));
        } catch (HttpException) {
            // Not ready to show yet: nothing to announce.
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Start the draft: the first participant's turn, and their clock, begins now.
     *
     * Who may do this is decided by DraftPolicy::start (the host). Here the draft
     * has to be ready: items to pick, and every participant joined and in the
     * order. The host may start it at any time; the scheduled start time is only
     * a schedule. Starting a draft that has already started does nothing, so a
     * double click is harmless.
     */
    public function start(Draft $draft): void
    {
        $started = DB::transaction(function () use ($draft) {
            $draft = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if ($draft->turn_started_at !== null) {
                return false;
            }

            abort_if($draft->interests()->doesntExist(), 422, 'This draft has no items to select.');
            $this->assertReady($this->teams($draft));

            $draft->update(['turn_started_at' => now()]);

            return true;
        });

        if ($started) {
            $this->broadcast($this->state($draft));
        }
    }

    /**
     * Skip the current turn once its time is up. Returns the participant whose
     * turn was skipped, if any.
     *
     * Anyone in the draft may cause this to run, but only the server's clock
     * decides whether it does anything, so a client cannot skip a turn early.
     * At most one turn is skipped per call, and the next turn's clock starts
     * when the skip is recorded: if everyone walks away, the first person back
     * finds one skipped turn, not a pile of them.
     */
    public function advanceClock(Draft $draft): ?Team
    {
        if (! $this->turnHasExpired($draft) || $this->isComplete($draft)) {
            return null;
        }

        $skipped = null;

        $changed = DB::transaction(function () use ($draft, &$skipped) {
            // Same lock as pick(), so a skip and a pick can never both apply to one turn.
            $draft = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            // Someone else may have got here between the check above and the lock.
            if (! $this->turnHasExpired($draft) || $this->isComplete($draft)) {
                return false;
            }

            $teams = $this->teams($draft);
            $this->assertReady($teams);

            $turnNumber = $this->turnNumber($draft);
            $team = $teams[$turnNumber % $teams->count()];

            try {
                TurnSkip::create(['draft_id' => $draft->id, 'team_id' => $team->id, 'turn_number' => $turnNumber]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            $draft->update(['turn_started_at' => now()]);
            $skipped = $team;

            return true;
        });

        if ($changed) {
            $this->broadcast($this->state($draft));
        }

        return $skipped;
    }

    /**
     * Record a selection for $user, enforcing every rule on the server. The
     * acting participant always comes from the authenticated user, never from
     * anything the client sends. Returns the state after the pick.
     */
    public function pick(Draft $draft, User $user, int $interestId): array
    {
        // Outsiders are refused before they can have any effect on the clock.
        abort_unless(
            $draft->teams()->where('user_id', $user->id)->exists(),
            403,
            'You are not a participant in this draft.'
        );

        // A pick that arrives after the deadline must not succeed, so settle the clock first.
        $skipped = $this->advanceClock($draft);
        abort_if(
            $skipped && (int) $skipped->user_id === (int) $user->id,
            409,
            'Your time ran out, so your turn was skipped.'
        );

        DB::transaction(function () use ($draft, $user, $interestId) {
            // Picks are serialised on the draft row, so the turn and availability
            // checks below always see the result of the previous pick. Nothing may
            // read pick data before this lock is taken.
            $draft = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            $teams = $this->teams($draft);
            abort_unless($teams->contains('user_id', $user->id), 403, 'You are not a participant in this draft.');
            $this->assertReady($teams);
            abort_if($draft->turn_started_at === null, 409, 'The host has not started this draft yet.');

            $currentTeam = $teams[$this->turnNumber($draft) % $teams->count()];
            abort_unless((int) $currentTeam->user_id === (int) $user->id, 403, 'It is not your turn to make a selection.');
            abort_if($this->deadline($draft)->lte(now()), 409, 'Your time ran out. Refresh to see whose turn it is.');

            $interest = Interest::where('draft_id', $draft->id)->whereKey($interestId)->first();
            abort_unless($interest, 404, 'That item is not part of this draft.');
            abort_if(
                Selection::where('draft_id', $draft->id)->where('interest_id', $interest->id)->exists(),
                409,
                'This item has already been selected.'
            );

            try {
                Selection::create([
                    'interest_id' => $interest->id,
                    'team_id' => $currentTeam->id,
                    'draft_id' => $draft->id,
                    'selected' => $interest->name,
                    'is_selected' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The unique (draft_id, interest_id) index is the last line of defence.
                abort(409, 'This item has already been selected.');
            }

            // The next participant's clock starts now.
            $draft->update(['turn_started_at' => now()]);
        });

        $state = $this->state($draft);
        $this->broadcast($state);

        return $state;
    }

    /**
     * The draft's participants in selection order.
     */
    private function teams(Draft $draft): Collection
    {
        return $draft->teams()->with('user')->orderBy('selection_no')->orderBy('id')->get()->values();
    }

    private function assertReady(Collection $teams): void
    {
        abort_if($teams->isEmpty(), 422, 'This draft has no participants.');
        abort_if(
            $teams->contains(fn ($team) => is_null($team->user_id)),
            422,
            'Every invited participant must join before picking starts.'
        );
        abort_if(
            $teams->contains(fn ($team) => is_null($team->selection_no)),
            422,
            'Every participant must have a selection order before picking starts.'
        );
    }

    /**
     * Turns used so far: every pick and every skip moves the draft on by one.
     */
    private function turnNumber(Draft $draft): int
    {
        return Selection::where('draft_id', $draft->id)->count() + TurnSkip::where('draft_id', $draft->id)->count();
    }

    private function isComplete(Draft $draft): bool
    {
        return Selection::where('draft_id', $draft->id)->count() >= $draft->interests()->count();
    }

    /**
     * A fingerprint of what the picking page is built from: the items and the
     * players in order. A page that is sent a different one is out of date (the
     * host edited the draft) and reloads itself.
     */
    private function layout(Draft $draft, Collection $teams): string
    {
        $items = $draft->interests()->orderBy('id')->get(['id', 'name', 'image_path'])
            ->map(fn ($item) => [$item->id, $item->name, $item->image_path]);
        $players = $teams->map(fn ($team) => [(int) $team->user_id, $team->user->username, (int) $team->selection_no]);

        return md5(json_encode([$items->all(), $players->all()]));
    }

    /**
     * True when the draft is running and the current turn's time is up.
     */
    private function turnHasExpired(Draft $draft): bool
    {
        return $draft->turn_started_at !== null && $this->deadline($draft)->lte(now());
    }

    private function deadline(Draft $draft): Carbon
    {
        return $draft->turn_started_at->copy()->addSeconds((int) $draft->selection_time_limit);
    }

    /**
     * The change is already committed, so a broadcaster outage must not fail
     * the request. Clients recover through the state endpoint.
     */
    private function broadcast(array $state): void
    {
        try {
            DraftStateChanged::dispatch($state);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
