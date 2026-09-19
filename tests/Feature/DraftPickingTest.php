<?php

namespace Tests\Feature;

use App\Events\DraftStateChanged;
use App\Models\Draft;
use App\Models\Interest;
use App\Models\Selection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

class DraftPickingTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    private Draft $draft;

    /** @var Collection<int, User> Participants, in selection order. */
    private Collection $players;

    /** @var Collection<int, Interest> */
    private Collection $interests;

    protected function setUp(): void
    {
        parent::setUp();

        // Time is frozen and the draft already started, so the turn timer never interferes
        // with what these tests check. Timing is covered in DraftTimerTest.
        Carbon::setTestNow('2026-09-19 12:00:00');

        [$this->draft, $this->players, $this->interests] = $this->buildDraft(3, 4, ['turn_started_at' => now()]);
    }

    private function pick(User $user, Interest $interest, array $extra = [])
    {
        return $this->actingAs($user)->postJson(
            route('select.interest', $this->draft->id),
            ['interest_id' => $interest->id] + $extra
        );
    }

    private function selectionCount(): int
    {
        return Selection::where('draft_id', $this->draft->id)->count();
    }

    public function test_participant_whose_turn_it_is_can_select_an_item(): void
    {
        $response = $this->pick($this->players[0], $this->interests[0]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('state.current_player.id', $this->players[1]->id)
            ->assertJsonPath('state.selected_interest_ids', [$this->interests[0]->id]);

        $selection = Selection::where('draft_id', $this->draft->id)->sole();
        $this->assertSame($this->interests[0]->id, $selection->interest_id);
        $this->assertSame($this->players[0]->id, $selection->team->user_id);
    }

    public function test_participant_cannot_select_out_of_turn(): void
    {
        $this->pick($this->players[1], $this->interests[0])->assertForbidden();

        $this->assertSame(0, $this->selectionCount());
    }

    public function test_a_forged_player_id_cannot_pick_on_behalf_of_someone_else(): void
    {
        $this->pick($this->players[1], $this->interests[0], ['player_id' => $this->players[0]->id])
            ->assertForbidden();

        $this->assertSame(0, $this->selectionCount());
    }

    public function test_user_outside_the_draft_cannot_select(): void
    {
        $this->pick(User::factory()->create(), $this->interests[0])->assertForbidden();

        $this->assertSame(0, $this->selectionCount());
    }

    public function test_guest_cannot_select(): void
    {
        $this->postJson(route('select.interest', $this->draft->id), ['interest_id' => $this->interests[0]->id])
            ->assertUnauthorized();

        $this->assertSame(0, $this->selectionCount());
    }

    public function test_an_item_that_is_already_selected_cannot_be_selected_again(): void
    {
        $this->pick($this->players[0], $this->interests[0])->assertOk();

        $this->pick($this->players[1], $this->interests[0])->assertStatus(409);

        $this->assertSame(1, $this->selectionCount());
        // The rejected attempt must not use up player 2's turn.
        $this->pick($this->players[1], $this->interests[1])->assertOk();
    }

    public function test_an_item_from_another_draft_cannot_be_selected(): void
    {
        $foreign = Interest::factory()->create();

        $this->pick($this->players[0], $foreign)->assertNotFound();

        $this->assertSame(0, Selection::count());
    }

    public function test_interest_id_is_required(): void
    {
        $this->actingAs($this->players[0])
            ->postJson(route('select.interest', $this->draft->id), [])
            ->assertUnprocessable();
    }

    public function test_turns_rotate_in_selection_order_and_wrap_around(): void
    {
        $this->pick($this->players[0], $this->interests[0])->assertOk();
        $this->pick($this->players[0], $this->interests[1])->assertForbidden();

        $this->pick($this->players[1], $this->interests[1])->assertOk();
        $this->pick($this->players[2], $this->interests[2])->assertOk();

        // Back to the first participant.
        $this->pick($this->players[1], $this->interests[3])->assertForbidden();
        $this->pick($this->players[0], $this->interests[3])
            ->assertOk()
            ->assertJsonPath('state.status', 'complete')
            ->assertJsonPath('state.current_player', null);
    }

    public function test_nothing_can_be_selected_once_every_item_is_taken(): void
    {
        foreach ([0, 1, 2, 0] as $i => $player) {
            $this->pick($this->players[$player], $this->interests[$i])->assertOk();
        }

        $this->pick($this->players[1], $this->interests[0])->assertStatus(409);

        $this->assertSame(4, $this->selectionCount());
    }

    public function test_picking_is_refused_until_every_participant_has_joined(): void
    {
        Team::where('draft_id', $this->draft->id)->where('selection_no', 3)->update(['user_id' => null]);

        $this->pick($this->players[0], $this->interests[0])->assertUnprocessable();

        $this->assertSame(0, $this->selectionCount());
    }

    public function test_picking_is_refused_until_every_participant_has_a_selection_order(): void
    {
        Team::where('draft_id', $this->draft->id)->where('selection_no', 3)->update(['selection_no' => null]);

        $this->pick($this->players[0], $this->interests[0])->assertUnprocessable();

        $this->assertSame(0, $this->selectionCount());
    }

    public function test_the_database_rejects_a_second_selection_of_the_same_item(): void
    {
        $team = Team::where('draft_id', $this->draft->id)->first();
        $attributes = [
            'interest_id' => $this->interests[0]->id,
            'team_id' => $team->id,
            'draft_id' => $this->draft->id,
            'selected' => $this->interests[0]->name,
            'is_selected' => true,
        ];

        Selection::create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);
        Selection::create($attributes);
    }

    public function test_a_successful_selection_is_broadcast_to_the_draft_channel(): void
    {
        Event::fake([DraftStateChanged::class]);

        $this->pick($this->players[0], $this->interests[0])->assertOk();

        Event::assertDispatched(DraftStateChanged::class, function (DraftStateChanged $event) {
            return $event->broadcastOn()->name === 'private-draft.'.$this->draft->id
                && $event->broadcastAs() === 'state.changed'
                && $event->broadcastWith()['pick_count'] === 1
                && $event->broadcastWith()['current_player']['id'] === $this->players[1]->id
                && $event->broadcastWith()['selected_interest_ids'] === [$this->interests[0]->id];
        });
    }

    public function test_rejected_selections_are_not_broadcast(): void
    {
        Event::fake([DraftStateChanged::class]);

        $this->pick($this->players[1], $this->interests[0])->assertForbidden();
        $this->pick(User::factory()->create(), $this->interests[0])->assertForbidden();

        Event::assertNotDispatched(DraftStateChanged::class);
    }

    public function test_a_broadcaster_failure_does_not_undo_a_committed_selection(): void
    {
        Event::listen(DraftStateChanged::class, function () {
            throw new \RuntimeException('Broadcaster is down.');
        });

        $this->pick($this->players[0], $this->interests[0])->assertOk();

        $this->assertSame(1, $this->selectionCount());
    }

    public function test_participant_can_fetch_the_current_state(): void
    {
        $this->pick($this->players[0], $this->interests[0])->assertOk();

        $this->actingAs($this->players[2])
            ->getJson(route('draft.state', $this->draft->id))
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('pick_count', 1)
            ->assertJsonPath('current_player.id', $this->players[1]->id)
            ->assertJsonPath('last_pick.player_id', $this->players[0]->id)
            ->assertJsonPath('last_pick.interest_id', $this->interests[0]->id)
            ->assertJsonCount(3, 'players');
    }

    public function test_only_the_host_and_participants_can_fetch_the_state(): void
    {
        $this->actingAs($this->players[0])->getJson(route('draft.state', $this->draft->id))->assertOk();
        $this->actingAs($this->draft->creator)->getJson(route('draft.state', $this->draft->id))->assertOk();

        $this->actingAs(User::factory()->create())
            ->getJson(route('draft.state', $this->draft->id))
            ->assertForbidden();

        auth()->logout();

        $this->getJson(route('draft.state', $this->draft->id))->assertUnauthorized();
    }

    public function test_only_the_host_and_participants_can_open_the_picking_page(): void
    {
        $this->withoutVite();

        $this->actingAs($this->players[0])
            ->get(route('show.interests', $this->draft->id))
            ->assertOk()
            ->assertSee($this->interests[0]->name)
            ->assertDontSee('You are watching as the host');

        $this->actingAs($this->draft->creator)
            ->get(route('show.interests', $this->draft->id))
            ->assertOk()
            ->assertSee($this->interests[0]->name)
            ->assertSee('You are watching as the host');

        $this->actingAs(User::factory()->create())
            ->get(route('show.interests', $this->draft->id))
            ->assertForbidden();
    }

    public function test_a_host_who_is_also_a_participant_is_not_shown_as_a_spectator(): void
    {
        $this->withoutVite();
        $this->draft->update(['user_id' => $this->players[1]->id]);

        $this->actingAs($this->players[1])
            ->get(route('show.interests', $this->draft->id))
            ->assertOk()
            ->assertDontSee('You are watching as the host');
    }

    public function test_the_host_can_watch_but_cannot_pick(): void
    {
        $host = $this->draft->creator;

        $this->pick($host, $this->interests[0])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not a participant in this draft.');

        $this->assertSame(0, $this->selectionCount());
    }

    public function test_the_picking_page_of_a_draft_that_is_not_ready_explains_why(): void
    {
        $this->withoutVite();
        Team::where('draft_id', $this->draft->id)->where('selection_no', 3)->update(['user_id' => null]);

        $this->actingAs($this->players[0])
            ->get(route('show.interests', $this->draft->id))
            ->assertRedirect(route('draft.details', $this->draft->id))
            ->assertSessionHasErrors(['start' => 'Every invited participant must join before picking starts.']);
    }

    public function test_only_the_host_and_participants_can_listen_to_the_draft_channel(): void
    {
        $authorize = $this->registeredChannelCallback('draft.{draftId}');

        $this->assertTrue((bool) $authorize($this->players[0], $this->draft->id));
        $this->assertTrue((bool) $authorize($this->draft->creator, $this->draft->id));
        $this->assertFalse((bool) $authorize(User::factory()->create(), $this->draft->id));
        $this->assertFalse((bool) $authorize($this->players[0], 999999), 'a draft that does not exist');
    }

    /**
     * The callback registered in routes/channels.php for a channel pattern.
     */
    private function registeredChannelCallback(string $pattern): callable
    {
        $broadcaster = app(\Illuminate\Broadcasting\BroadcastManager::class)->driver();
        $channels = (fn () => $this->channels)->call($broadcaster);

        $this->assertArrayHasKey($pattern, $channels);

        return $channels[$pattern];
    }
}
