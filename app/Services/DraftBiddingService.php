<?php

namespace App\Services;

use App\Events\DraftStateChanged;
use App\Models\Bid;
use App\Models\Draft;
use App\Models\Interest;
use App\Models\Team;
use App\Models\User;
use App\Services\Contracts\DraftFlowService;
use App\Services\Support\DraftClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Bidding drafts: items go up for auction one at a time, in a fixed sequence
 * (order_mode decides that sequence, the same way it decides participant order
 * for Giveaway — see items()). Anyone may bid on the item currently open, at
 * any time while it is open; the highest bid when its window closes wins it,
 * or it goes unsold if nobody bid. There is no per-participant turn.
 */
class DraftBiddingService implements DraftFlowService
{
    public function __construct(private DraftClock $clock)
    {
    }

    /**
     * The state every participant sees: which item is open, the current
     * leading bid, and the outcome of every item decided so far.
     *
     * Clients treat "version" as a counter that only goes up and ignore any
     * snapshot older than the one they already hold, exactly as the Giveaway
     * picking page does.
     */
    public function state(Draft $draft): array
    {
        $draft = Draft::findOrFail($draft->id);
        $items = $this->items($draft);
        $teams = $this->teams($draft);
        $this->assertReady($teams, $items);

        $closedCount = $items->filter(fn ($item) => $item->closed_at !== null)->count();
        $isComplete = $this->isComplete($draft, $items, $closedCount);
        $isStarted = $draft->turn_started_at !== null;

        $status = match (true) {
            $isComplete => 'complete',
            $isStarted => 'in_progress',
            $draft->start_date->gt(now()) => 'scheduled',
            default => 'waiting',
        };

        $playersByTeam = $teams->mapWithKeys(fn ($team) => [$team->id => [
            'id' => (int) $team->user_id,
            'username' => $team->user->username,
        ]]);

        $currentItem = $status === 'in_progress' ? $items[$closedCount] : null;
        $currentBid = $currentItem ? $this->leadingBid($currentItem->id) : null;
        $lastClosed = $items->filter(fn ($item) => $item->closed_at !== null)->sortByDesc('closed_at')->first();

        return [
            'draft_id' => $draft->id,
            'status' => $status,
            'version' => $closedCount + Bid::where('draft_id', $draft->id)->count() + ($isStarted ? 1 : 0),
            'items_closed' => $closedCount,
            'time_limit' => (int) $draft->selection_time_limit,
            'starts_at' => $draft->start_date->toIso8601String(),
            'item_ends_at' => $status === 'in_progress' ? $this->clock->deadline($draft)->toIso8601String() : null,
            'server_time' => now()->toIso8601String(),
            'current_item' => $currentItem ? [
                'id' => $currentItem->id,
                'name' => $currentItem->name,
                'image_path' => $currentItem->image_path,
            ] : null,
            'current_bid' => $currentBid ? [
                'amount' => (int) $currentBid->amount,
                'player_id' => $playersByTeam[$currentBid->team_id]['id'],
                'player_username' => $playersByTeam[$currentBid->team_id]['username'],
            ] : null,
            'players' => $playersByTeam->values()->all(),
            'layout' => $this->layout($items, $teams),
            'items' => $items->map(fn ($item) => $this->itemSummary($item, $currentItem, $playersByTeam))->values()->all(),
            'last_closed' => $lastClosed ? $this->closedSummary($lastClosed, $playersByTeam) : null,
        ];
    }

    /**
     * Tell everyone on the bidding page that something changed (the host
     * edited the draft). A draft that cannot show a state yet has nothing to
     * announce. This runs after the change is committed and is best-effort: it
     * never throws, so a failure here cannot undo work that is already saved.
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
     * Start the draft: the first item's auction window begins now.
     *
     * Who may do this is decided by DraftPolicy::start (the host). Here the
     * draft has to be ready: items to sell, and every participant joined.
     * Under order_mode 'random' the item sequence is shuffled here, once,
     * right before starting. Starting a draft that has already started does
     * nothing, so a double click is harmless.
     */
    public function start(Draft $draft): void
    {
        $started = DB::transaction(function () use ($draft) {
            $draft = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if ($draft->turn_started_at !== null) {
                return false;
            }

            $items = $this->items($draft);
            if ($draft->order_mode === 'random') {
                $this->assignRandomOrder($items);
            }
            $this->assertReady($this->teams($draft), $items);

            $draft->update(['turn_started_at' => now()]);

            return true;
        });

        if ($started) {
            $this->broadcast($this->state($draft));
        }
    }

    /**
     * Close the currently open item once its time is up: highest bid wins it,
     * or it goes unsold if there were none. Returns the item that was closed,
     * if any. Anyone in the draft may cause this to run, but only the server's
     * clock decides whether it does anything.
     */
    public function advanceClock(Draft $draft): ?Interest
    {
        if (! $this->clock->hasExpired($draft) || $this->isComplete($draft)) {
            return null;
        }

        $closed = null;

        $changed = DB::transaction(function () use ($draft, &$closed) {
            // Same lock as placeBid(), so a close and a bid can never both apply to one item.
            $draft = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if (! $this->clock->hasExpired($draft) || $this->isComplete($draft)) {
                return false;
            }

            $items = $this->items($draft);
            $this->assertReady($this->teams($draft), $items);

            $closedCount = $items->filter(fn ($item) => $item->closed_at !== null)->count();
            $item = $items[$closedCount];
            $winningBid = $this->leadingBid($item->id);

            // Conditional on closed_at still being null: belt-and-suspenders against a
            // concurrent close, on top of the draft lock that already prevents one.
            $affected = Interest::whereKey($item->id)->whereNull('closed_at')->update([
                'closed_at' => now(),
                'winning_team_id' => $winningBid?->team_id,
                'winning_amount' => $winningBid?->amount,
            ]);

            if ($affected === 0) {
                return false;
            }

            // The next item's clock starts now.
            $draft->update(['turn_started_at' => now()]);
            $closed = $item->fresh();

            return true;
        });

        if ($changed) {
            $this->broadcast($this->state($draft));
        }

        return $closed;
    }

    /**
     * Record a bid for $user on $interestId, enforcing every rule on the
     * server. The bidder always comes from the authenticated user, never from
     * anything the client sends. Returns the state after the bid.
     */
    public function placeBid(Draft $draft, User $user, int $interestId, int $amount): array
    {
        // Outsiders are refused before they can have any effect on the clock.
        abort_unless(
            $draft->teams()->where('user_id', $user->id)->exists(),
            403,
            'You are not a participant in this draft.'
        );

        // A bid that arrives after the window closed must not succeed, so settle the clock first.
        $closed = $this->advanceClock($draft);
        abort_if(
            $closed && $closed->id === $interestId,
            409,
            'Bidding on this item just closed. Refresh to see what is open.'
        );

        DB::transaction(function () use ($draft, $user, $interestId, $amount) {
            // Bids are serialised on the draft row, so the "is this the open item, and is
            // this bid high enough" checks below always see the result of the previous bid.
            $draft = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            $team = $draft->teams()->where('user_id', $user->id)->first();
            abort_unless($team, 403, 'You are not a participant in this draft.');

            $items = $this->items($draft);
            $this->assertReady($this->teams($draft), $items);
            abort_if($draft->turn_started_at === null, 409, 'The host has not started this draft yet.');
            abort_if($this->clock->deadline($draft)->lte(now()), 409, 'Bidding on this item has closed. Refresh to see what is open.');

            $closedCount = $items->filter(fn ($item) => $item->closed_at !== null)->count();
            $currentItem = $items[$closedCount] ?? null;
            abort_unless($currentItem && $currentItem->id === $interestId, 409, 'That item is not open for bidding right now.');

            $leading = $this->leadingBid($currentItem->id);
            abort_if(
                $leading && $amount <= $leading->amount,
                409,
                'Someone has already bid at least that much. Bid higher.'
            );

            Bid::create([
                'draft_id' => $draft->id,
                'interest_id' => $currentItem->id,
                'team_id' => $team->id,
                'amount' => $amount,
            ]);
        });

        $state = $this->state($draft);
        $this->broadcast($state);

        return $state;
    }

    /**
     * The draft's items in auction order.
     */
    private function items(Draft $draft): Collection
    {
        return $draft->interests()->orderBy('position')->orderBy('id')->get()->values();
    }

    /**
     * The draft's participants. Bidding has no turn order among them, so
     * there is no order_mode-driven ordering here — only items get one.
     */
    private function teams(Draft $draft): Collection
    {
        return $draft->teams()->with('user')->orderBy('id')->get()->values();
    }

    private function assertReady(Collection $teams, Collection $items): void
    {
        abort_if($items->isEmpty(), 422, 'This draft has no items to select.');
        abort_if($teams->isEmpty(), 422, 'This draft has no participants.');
        abort_if(
            $teams->contains(fn ($team) => is_null($team->user_id)),
            422,
            'Every invited participant must join before picking starts.'
        );
    }

    /**
     * order_mode 'random': give every item a fresh, shuffled place in the
     * auction sequence. Called only from start(), under its lock, right
     * before the draft begins.
     */
    private function assignRandomOrder(Collection $items): void
    {
        $items->shuffle()->values()->each(
            fn (Interest $item, int $index) => $item->update(['position' => $index + 1])
        );
    }

    /**
     * An item's current leading bid: because placeBid() only ever accepts a
     * bid strictly higher than the one before it, the latest bid row for an
     * item is always its leader — no MAX() query needed.
     */
    private function leadingBid(int $interestId): ?Bid
    {
        return Bid::where('interest_id', $interestId)->orderByDesc('id')->first();
    }

    private function isComplete(Draft $draft, ?Collection $items = null, ?int $closedCount = null): bool
    {
        $items ??= $this->items($draft);
        $closedCount ??= $items->filter(fn ($item) => $item->closed_at !== null)->count();

        return $items->isNotEmpty() && $closedCount >= $items->count();
    }

    /**
     * A fingerprint of what the bidding page is built from: the items, their
     * order, and the players. A page sent a different one is out of date (the
     * host edited the draft) and reloads itself — same idea as the Giveaway
     * picking page's layout fingerprint.
     */
    private function layout(Collection $items, Collection $teams): string
    {
        $itemData = $items->map(fn ($item) => [$item->id, $item->name, $item->image_path, $item->position]);
        $playerData = $teams->map(fn ($team) => [(int) $team->user_id, $team->user->username]);

        return md5(json_encode([$itemData->all(), $playerData->all()]));
    }

    private function itemSummary(Interest $item, ?Interest $currentItem, Collection $playersByTeam): array
    {
        $status = match (true) {
            $item->closed_at !== null => $item->winning_team_id !== null ? 'sold' : 'unsold',
            $currentItem !== null && $item->id === $currentItem->id => 'open',
            default => 'upcoming',
        };

        return [
            'id' => $item->id,
            'name' => $item->name,
            'image_path' => $item->image_path,
            'status' => $status,
            'winning_username' => $item->winning_team_id !== null ? ($playersByTeam[$item->winning_team_id]['username'] ?? null) : null,
            'winning_amount' => $item->winning_amount !== null ? (int) $item->winning_amount : null,
        ];
    }

    private function closedSummary(Interest $item, Collection $playersByTeam): array
    {
        return [
            'interest_name' => $item->name,
            'sold' => $item->winning_team_id !== null,
            'winning_username' => $item->winning_team_id !== null ? ($playersByTeam[$item->winning_team_id]['username'] ?? null) : null,
            'winning_amount' => $item->winning_amount !== null ? (int) $item->winning_amount : null,
        ];
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
