<?php

namespace Tests\Feature;

use App\Events\DraftStateChanged;
use App\Models\Bid;
use App\Models\Draft;
use App\Models\Interest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

/**
 * Bidding drafts: items go up for auction one at a time, in a fixed sequence.
 * Anyone may bid on the item currently open; the highest bid wins it when its
 * window closes, or it goes unsold if nobody bid. Time is frozen in every test
 * and only moves when a test moves it.
 */
class BiddingTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    private const LIMIT = 60;

    private Carbon $start;

    private Draft $draft;

    /** @var Collection<int, User> */
    private Collection $players;

    /** @var Collection<int, Interest> in auction order */
    private Collection $items;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = Carbon::parse('2026-09-23 12:00:00');
        Carbon::setTestNow($this->start);

        [$this->draft, $this->players, $this->items] = $this->buildBiddingDraft(3, 3, [
            'selection_time_limit' => self::LIMIT,
            'start_date' => $this->start->copy()->subHour(),
        ]);
    }

    private function at(int $seconds): void
    {
        Carbon::setTestNow($this->start->copy()->addSeconds($seconds));
    }

    private function startDraft(): void
    {
        $this->actingAs($this->draft->creator)->post(route('start.draft', $this->draft->id));
    }

    private function state(User $user)
    {
        return $this->actingAs($user)->getJson(route('draft.state', $this->draft->id));
    }

    private function bid(User $user, Interest $item, int $amount)
    {
        return $this->actingAs($user)->postJson(route('place.bid', $this->draft->id), [
            'interest_id' => $item->id,
            'amount' => $amount,
        ]);
    }

    // ------------------------------------------------------------------ starting

    public function test_the_host_starts_it_and_the_first_item_opens(): void
    {
        $this->startDraft();

        $response = $this->state($this->players[0])
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('current_item.id', $this->items[0]->id)
            ->assertJsonPath('current_bid', null)
            ->assertJsonPath('items_closed', 0);

        $this->assertNotNull($this->draft->fresh()->turn_started_at);
    }

    public function test_cannot_start_without_items(): void
    {
        Interest::where('draft_id', $this->draft->id)->delete();

        $this->actingAs($this->draft->creator)
            ->post(route('start.draft', $this->draft->id))
            ->assertSessionHasErrors(['start' => 'This draft has no items to select.']);

        $this->assertNull($this->draft->fresh()->turn_started_at);
    }

    public function test_cannot_start_until_everyone_invited_has_joined(): void
    {
        Team::where('draft_id', $this->draft->id)->first()->update(['user_id' => null]);

        $this->actingAs($this->draft->creator)
            ->post(route('start.draft', $this->draft->id))
            ->assertSessionHasErrors(['start' => 'Every invited participant must join before picking starts.']);

        $this->assertNull($this->draft->fresh()->turn_started_at);
    }

    public function test_only_the_host_can_start_it(): void
    {
        $this->actingAs($this->players[0])->post(route('start.draft', $this->draft->id))->assertForbidden();

        $this->assertNull($this->draft->fresh()->turn_started_at);
    }

    // ------------------------------------------------------------------ placing bids

    public function test_a_participant_can_bid_on_the_open_item(): void
    {
        $this->startDraft();

        $this->bid($this->players[0], $this->items[0], 10)
            ->assertOk()
            ->assertJsonPath('state.current_bid.amount', 10)
            ->assertJsonPath('state.current_bid.player_id', $this->players[0]->id);

        $this->assertSame(1, Bid::where('draft_id', $this->draft->id)->count());
    }

    public function test_the_first_bid_can_be_any_positive_amount(): void
    {
        $this->startDraft();

        $this->bid($this->players[0], $this->items[0], 1)->assertOk();

        $this->assertSame(1, Bid::sole()->amount);
    }

    public function test_a_bid_must_be_strictly_higher_than_the_current_one(): void
    {
        $this->startDraft();
        $this->bid($this->players[0], $this->items[0], 10)->assertOk();

        $this->bid($this->players[1], $this->items[0], 10)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Someone has already bid at least that much. Bid higher.');
        $this->bid($this->players[1], $this->items[0], 9)->assertStatus(409);

        $this->bid($this->players[1], $this->items[0], 11)->assertOk();

        $this->assertSame(2, Bid::where('draft_id', $this->draft->id)->count());
    }

    public function test_a_participant_can_raise_their_own_leading_bid(): void
    {
        $this->startDraft();
        $this->bid($this->players[0], $this->items[0], 10)->assertOk();

        $this->bid($this->players[0], $this->items[0], 20)
            ->assertOk()
            ->assertJsonPath('state.current_bid.amount', 20);
    }

    public function test_amount_must_be_a_positive_integer(): void
    {
        $this->startDraft();

        $this->actingAs($this->players[0])
            ->postJson(route('place.bid', $this->draft->id), ['interest_id' => $this->items[0]->id, 'amount' => 0])
            ->assertUnprocessable();
        $this->actingAs($this->players[0])
            ->postJson(route('place.bid', $this->draft->id), ['interest_id' => $this->items[0]->id])
            ->assertUnprocessable();

        $this->assertSame(0, Bid::count());
    }

    public function test_a_non_participant_cannot_bid(): void
    {
        $this->startDraft();

        $this->bid(User::factory()->create(), $this->items[0], 10)
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not a participant in this draft.');

        $this->assertSame(0, Bid::count());
    }

    public function test_the_host_cannot_bid_if_they_are_not_a_participant(): void
    {
        $this->startDraft();

        $this->bid($this->draft->creator, $this->items[0], 10)->assertForbidden();

        $this->assertSame(0, Bid::count());
    }

    public function test_a_guest_cannot_bid(): void
    {
        $this->startDraft();
        // startDraft() acted as the host; actingAs() persists for the rest of the test unless
        // explicitly cleared, so without this the "guest" request below would still be the host.
        auth()->logout();

        $this->postJson(route('place.bid', $this->draft->id), ['interest_id' => $this->items[0]->id, 'amount' => 10])
            ->assertUnauthorized();

        $this->assertSame(0, Bid::count());
    }

    public function test_bidding_before_the_host_starts_is_refused(): void
    {
        $this->bid($this->players[0], $this->items[0], 10)
            ->assertStatus(409)
            ->assertJsonPath('message', 'The host has not started this draft yet.');

        $this->assertSame(0, Bid::count());
    }

    public function test_bidding_on_an_item_that_is_not_the_one_open_is_refused(): void
    {
        $this->startDraft();

        $this->bid($this->players[0], $this->items[1], 10)
            ->assertStatus(409)
            ->assertJsonPath('message', 'That item is not open for bidding right now.');

        $this->assertSame(0, Bid::count());
    }

    // ------------------------------------------------------------------ closing items

    public function test_an_item_with_no_bids_goes_unsold_when_its_window_expires(): void
    {
        $this->startDraft();
        Event::fake([DraftStateChanged::class]);

        $this->at(self::LIMIT);
        $response = $this->state($this->players[0])
            ->assertOk()
            ->assertJsonPath('items_closed', 1)
            ->assertJsonPath('last_closed.sold', false)
            ->assertJsonPath('current_item.id', $this->items[1]->id);

        $first = $this->items[0]->fresh();
        $this->assertNotNull($first->closed_at);
        $this->assertNull($first->winning_team_id);
        $this->assertNull($first->winning_amount);
        Event::assertDispatchedTimes(DraftStateChanged::class, 1);
    }

    public function test_an_item_with_bids_is_sold_to_the_highest_bidder_when_its_window_expires(): void
    {
        $this->startDraft();
        $this->bid($this->players[0], $this->items[0], 10)->assertOk();
        $this->bid($this->players[1], $this->items[0], 25)->assertOk();

        $this->at(self::LIMIT);
        $this->state($this->players[0])
            ->assertJsonPath('last_closed.sold', true)
            ->assertJsonPath('last_closed.winning_amount', 25)
            ->assertJsonPath('last_closed.winning_username', $this->players[1]->username);

        $first = $this->items[0]->fresh();
        $this->assertSame(25, $first->winning_amount);
        $this->assertSame($this->players[1]->id, $first->winningTeam->user_id);
    }

    public function test_the_next_items_clock_starts_when_the_previous_one_closes(): void
    {
        $this->startDraft();

        $this->at(self::LIMIT);
        $response = $this->state($this->players[0])->assertJsonPath('current_item.id', $this->items[1]->id);
        $this->assertTrue(
            Carbon::parse($response->json('item_ends_at'))->equalTo($this->start->copy()->addSeconds(self::LIMIT + self::LIMIT))
        );
    }

    public function test_a_bid_that_arrives_just_after_the_window_closed_is_refused_and_the_item_moves_on(): void
    {
        $this->startDraft();

        $this->at(self::LIMIT + 1);
        $this->bid($this->players[0], $this->items[0], 10)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Bidding on this item just closed. Refresh to see what is open.');

        $this->assertSame(0, Bid::count());
        $this->assertNotNull($this->items[0]->fresh()->closed_at);

        // The next item is open and can be bid on normally.
        $this->bid($this->players[0], $this->items[1], 10)->assertOk();
    }

    public function test_items_close_in_auction_order(): void
    {
        $this->startDraft();

        $this->at(self::LIMIT);
        $this->state($this->players[0]); // settles item 1
        $this->at(self::LIMIT * 2);
        $this->state($this->players[0])->assertJsonPath('current_item.id', $this->items[2]->id);

        $closed = Interest::where('draft_id', $this->draft->id)->whereNotNull('closed_at')
            ->orderBy('closed_at')->pluck('id')->all();
        $this->assertSame([$this->items[0]->id, $this->items[1]->id], $closed);
    }

    // ------------------------------------------------------------------ completion

    public function test_the_draft_completes_once_every_item_has_closed_whether_sold_or_not(): void
    {
        $this->startDraft();
        $this->bid($this->players[0], $this->items[0], 10)->assertOk();

        $this->at(self::LIMIT);
        $this->state($this->players[0]); // closes item 1 (sold), opens item 2
        $this->at(self::LIMIT * 2);
        $this->state($this->players[0]); // closes item 2 (unsold), opens item 3
        $this->at(self::LIMIT * 3);
        $response = $this->state($this->players[0])
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('current_item', null)
            ->assertJsonPath('item_ends_at', null)
            ->assertJsonPath('items_closed', 3);

        $this->assertSame(1, Interest::where('draft_id', $this->draft->id)->whereNotNull('winning_team_id')->count());
        $this->assertSame(2, Interest::where('draft_id', $this->draft->id)->whereNull('winning_team_id')->count());
    }

    public function test_no_clock_runs_once_the_draft_is_complete(): void
    {
        $this->startDraft();
        foreach ([0, 1, 2] as $i) {
            $this->at(self::LIMIT * ($i + 1));
            $this->state($this->players[0]);
        }
        $this->state($this->players[0])->assertJsonPath('status', 'complete');

        $this->at(self::LIMIT * 10);
        $this->state($this->players[0])->assertJsonPath('status', 'complete');
        $this->bid($this->players[0], $this->items[0], 999)->assertStatus(409);
    }

    // ------------------------------------------------------------------ live state / broadcasting

    public function test_the_version_only_ever_goes_up(): void
    {
        $versions = [];
        $versions[] = $this->state($this->players[0])->json('version'); // waiting for host
        $this->startDraft();
        $versions[] = $this->state($this->players[0])->json('version'); // in progress
        $versions[] = $this->bid($this->players[0], $this->items[0], 10)->json('state.version');
        $this->at(self::LIMIT);
        $versions[] = $this->state($this->players[1])->json('version'); // item closed

        $this->assertSame($versions, collect($versions)->sort()->unique()->values()->all());
    }

    public function test_a_bid_is_broadcast_to_the_draft_channel(): void
    {
        $this->startDraft();
        Event::fake([DraftStateChanged::class]);

        $this->bid($this->players[0], $this->items[0], 10)->assertOk();

        Event::assertDispatched(DraftStateChanged::class, function (DraftStateChanged $event) {
            return $event->broadcastOn()->name === 'private-draft.'.$this->draft->id
                && $event->broadcastAs() === 'state.changed'
                && $event->broadcastWith()['current_bid']['amount'] === 10;
        });
    }

    public function test_a_rejected_bid_is_not_broadcast(): void
    {
        $this->startDraft();
        Event::fake([DraftStateChanged::class]);

        $this->bid(User::factory()->create(), $this->items[0], 10)->assertForbidden();

        Event::assertNotDispatched(DraftStateChanged::class);
    }

    // ------------------------------------------------------------------ access control

    public function test_only_the_host_and_participants_can_see_the_draft_state(): void
    {
        $this->startDraft();

        $this->state($this->players[0])->assertOk();
        $this->actingAs($this->draft->creator)->getJson(route('draft.state', $this->draft->id))->assertOk();
        $this->actingAs(User::factory()->create())->getJson(route('draft.state', $this->draft->id))->assertForbidden();
    }

    public function test_only_the_host_and_participants_can_listen_to_the_draft_channel(): void
    {
        $broadcaster = app(\Illuminate\Broadcasting\BroadcastManager::class)->driver();
        $channels = (fn () => $this->channels)->call($broadcaster);
        $authorize = $channels['draft.{draftId}'];

        $this->assertTrue((bool) $authorize($this->players[0], $this->draft->id));
        $this->assertTrue((bool) $authorize($this->draft->creator, $this->draft->id));
        $this->assertFalse((bool) $authorize(User::factory()->create(), $this->draft->id));
    }

    // ------------------------------------------------------------------ editing lock

    public function test_a_bidding_draft_is_locked_for_editing_once_the_host_starts_it(): void
    {
        $this->startDraft();

        $this->assertTrue($this->draft->fresh()->hasStarted());
        $this->actingAs($this->draft->creator)->get(route('draft.edit', $this->draft->id))
            ->assertRedirect(route('draft.details', $this->draft->id));
    }

    public function test_hasStarted_also_recognises_a_draft_with_bids_recorded(): void
    {
        // Defence in depth: hasStarted() checks bids()->exists() independently of turn_started_at,
        // the same way it already double-checks selections() for Giveaway.
        $this->draft->update(['turn_started_at' => null]);
        Bid::create([
            'draft_id' => $this->draft->id,
            'interest_id' => $this->items[0]->id,
            'team_id' => Team::where('draft_id', $this->draft->id)->first()->id,
            'amount' => 5,
        ]);

        $this->assertTrue($this->draft->fresh()->hasStarted());
    }

    // ------------------------------------------------------------------ the page itself

    public function test_the_bidding_page_renders_for_a_participant_and_shows_the_host_watching(): void
    {
        $this->withoutVite();
        $this->startDraft();

        $this->actingAs($this->players[0])
            ->get(route('show.interests', $this->draft->id))
            ->assertOk()
            ->assertSee($this->items[0]->name)
            ->assertDontSee('You are watching as the host');

        $this->actingAs($this->draft->creator)
            ->get(route('show.interests', $this->draft->id))
            ->assertOk()
            ->assertSee('You are watching as the host');
    }

    public function test_a_non_participant_cannot_open_the_bidding_page(): void
    {
        $this->withoutVite();
        $this->startDraft();

        $this->actingAs(User::factory()->create())
            ->get(route('show.interests', $this->draft->id))
            ->assertForbidden();
    }
}
