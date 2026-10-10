<?php

namespace App\Services;

use App\Events\DraftStateChanged;
use App\Models\Draft;
use App\Models\PayoutTier;
use App\Models\Team;
use App\Services\Contracts\DraftFlowService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Giveaway drafts in 'money' mode: nobody picks anything. Participants are
 * ranked exactly the way Giveaway already ranks them for picking (host
 * decided, fcfs, or shuffled once at start under random) and the host's
 * payout table decides what each rank is paid. Pressing Start is a one-time
 * reveal, not the beginning of a turn-based session, so there is no clock and
 * no 'in_progress' phase: a draft is 'scheduled'/'waiting' and then, the
 * instant it starts, 'complete'.
 */
class DraftPayoutService implements DraftFlowService
{
    /**
     * The state everyone sees: before start, just the participant count and
     * how many payout tiers the host has set up; after start, the full
     * results table, rank by rank, so everyone (not only the highest ranks)
     * sees where they landed and what it paid.
     *
     * Clients treat "version" as a counter that only goes up and ignore any
     * snapshot older than the one they already hold, same as the other flows.
     */
    public function state(Draft $draft): array
    {
        $draft = Draft::findOrFail($draft->id);
        $teams = $this->teams($draft);
        $this->assertReady($teams, $draft);

        $tiers = $this->tiers($draft);
        $isStarted = $draft->turn_started_at !== null;

        $status = match (true) {
            $isStarted => 'complete',
            $draft->start_date->gt(now()) => 'scheduled',
            default => 'waiting',
        };

        $playersByTeam = $teams->mapWithKeys(fn ($team) => [$team->id => [
            'id' => (int) $team->user_id,
            'username' => $team->user->username,
            // Under 'random' nobody has a number until the reveal; before that it is null.
            'selection_no' => $team->selection_no === null ? null : (int) $team->selection_no,
        ]]);

        $results = $isStarted
            ? $teams->values()->map(fn ($team, $index) => [
                'rank' => $index + 1,
                'player_id' => $playersByTeam[$team->id]['id'],
                'player_username' => $playersByTeam[$team->id]['username'],
                'amount' => $this->amountForRank($tiers, $index + 1),
            ])->all()
            : [];

        return [
            'draft_id' => $draft->id,
            'status' => $status,
            'version' => ($isStarted ? 1 : 0),
            'starts_at' => $draft->start_date->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'order_mode' => $draft->order_mode,
            'players' => $playersByTeam->values()->all(),
            'tier_count' => $tiers->count(),
            'layout' => $this->layout($draft, $teams, $tiers),
            'results' => $results,
        ];
    }

    /**
     * Tell everyone on the results page that something about the draft
     * changed (the host edited it). A draft that cannot show a state yet has
     * nothing to announce. This runs after the change is committed and is
     * best-effort: it never throws, so a failure here cannot undo work that
     * is already saved.
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
     * Start the draft: the one-time reveal. Under order_mode 'random' the
     * order is shuffled here, once, right before revealing — same as
     * Giveaway picking. There is no clock to begin; the results are simply
     * computed and broadcast. Starting a draft that has already started does
     * nothing, so a double click is harmless.
     */
    public function start(Draft $draft): void
    {
        $started = DB::transaction(function () use ($draft) {
            $draft = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if ($draft->turn_started_at !== null) {
                return false;
            }

            abort_if($this->tiers($draft)->isEmpty(), 422, 'This draft has no payout tiers set up.');

            $teams = $this->teams($draft);
            if ($draft->order_mode === 'random') {
                $this->assignRandomOrder($teams);
            }
            $this->assertReady($teams, $draft);

            $draft->update(['turn_started_at' => now()]);

            return true;
        });

        if ($started) {
            $this->broadcast($this->state($draft));
        }
    }

    /**
     * There is no timer in money mode: the reveal happens once, at start,
     * and nothing further is ever due to happen on its own.
     */
    public function advanceClock(Draft $draft): mixed
    {
        return null;
    }

    /**
     * The draft's participants in rank order — identical ordering to
     * Giveaway's picking order (see DraftPickService::teams).
     */
    private function teams(Draft $draft): Collection
    {
        return $draft->teams()->with('user')->orderBy('selection_no')->orderBy('id')->get()->values();
    }

    /**
     * The host's payout table, cheapest rank first.
     */
    private function tiers(Draft $draft): Collection
    {
        return $draft->payoutTiers()->orderBy('rank_from')->get();
    }

    /**
     * @param  Draft  $draft  Only used to check order_mode, exactly as
     *                        DraftPickService::assertReady does — the "must
     *                        already have an order" rule only applies to
     *                        'host_decided'; 'fcfs' and 'random' already
     *                        guarantee one by the time this runs.
     */
    private function assertReady(Collection $teams, Draft $draft): void
    {
        abort_if($teams->isEmpty(), 422, 'This draft has no participants.');
        abort_if(
            $teams->contains(fn ($team) => is_null($team->user_id)),
            422,
            'Every invited participant must join before results can be shown.'
        );
        if ($draft->order_mode === 'host_decided') {
            abort_if(
                $teams->contains(fn ($team) => is_null($team->selection_no)),
                422,
                'Every participant must have a selection order before this draft can start.'
            );
        }
    }

    /**
     * order_mode 'random': give every team a fresh, shuffled rank. Called
     * only from start(), under its lock, right before the reveal.
     */
    private function assignRandomOrder(Collection $teams): void
    {
        $teams->shuffle()->values()->each(
            fn (Team $team, int $index) => $team->update(['selection_no' => $index + 1])
        );
    }

    /**
     * The amount paid to a given rank: whichever tier's range covers it, or
     * 0 if no tier does. Tiers are validated not to overlap when the host
     * sets them up (see DraftEditor::addPayoutTiers), so at most one matches.
     */
    private function amountForRank(Collection $tiers, int $rank): int
    {
        $tier = $tiers->first(fn (PayoutTier $tier) => $rank >= $tier->rank_from && $rank <= $tier->rank_to);

        return $tier ? (int) $tier->amount : 0;
    }

    /**
     * A fingerprint of what the results page is built from: the payout
     * tiers and the players in order. A page sent a different one is out of
     * date (the host edited the draft) and reloads itself — same idea as the
     * other two flows' layout fingerprints.
     */
    private function layout(Draft $draft, Collection $teams, Collection $tiers): string
    {
        $tierData = $tiers->map(fn ($tier) => [$tier->id, (int) $tier->rank_from, (int) $tier->rank_to, (int) $tier->amount]);
        // Under 'random' the numbers are handed out at the reveal, which the page shows live,
        // so they are not part of what it is built from (else the reveal would reload the page).
        $players = $teams->map(fn ($team) => [
            (int) $team->user_id,
            $team->user->username,
            $draft->order_mode === 'random' ? null : (int) $team->selection_no,
        ]);

        return md5(json_encode([$tierData->all(), $players->all()]));
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
