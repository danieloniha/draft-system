<?php

namespace Tests\Feature;

use App\Events\DraftStateChanged;
use App\Models\Draft;
use App\Models\Interest;
use App\Models\Selection;
use App\Models\Team;
use App\Models\TurnSkip;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

/**
 * Nothing can be picked until the host starts the draft, and once it has started
 * each turn is timed. Time is frozen in every test and only moves when a test moves
 * it, so nothing here depends on the wall clock.
 */
class DraftTimerTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    private const LIMIT = 60;

    private Carbon $start;

    private Draft $draft;

    /** @var Collection<int, User> Participants, in selection order. */
    private Collection $players;

    /** @var Collection<int, Interest> */
    private Collection $interests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = Carbon::parse('2026-09-19 12:00:00');
        Carbon::setTestNow($this->start);

        // The start time has passed, but the host has not started the draft yet.
        [$this->draft, $this->players, $this->interests] = $this->buildDraft(3, 4, [
            'selection_time_limit' => self::LIMIT,
            'start_date' => $this->start->copy()->subHour(),
        ]);
    }

    /** Move the clock to $seconds after the start. */
    private function at(int $seconds): void
    {
        Carbon::setTestNow($this->start->copy()->addSeconds($seconds));
    }

    /** The host presses "Start Draft". */
    private function startDraft()
    {
        return $this->actingAs($this->draft->creator)->post(route('start.draft', $this->draft->id));
    }

    private function state(User $user)
    {
        return $this->actingAs($user)->getJson(route('draft.state', $this->draft->id));
    }

    private function pick(User $user, Interest $interest)
    {
        return $this->actingAs($user)->postJson(
            route('select.interest', $this->draft->id),
            ['interest_id' => $interest->id]
        );
    }

    private function assertTime(int $secondsAfterStart, ?string $iso): void
    {
        $this->assertNotNull($iso);
        $this->assertTrue(
            Carbon::parse($iso)->equalTo($this->start->copy()->addSeconds($secondsAfterStart)),
            "Expected {$secondsAfterStart}s after the start, got {$iso}"
        );
    }

    // ------------------------------------------------------------------ before the draft starts

    public function test_nothing_can_be_picked_before_the_host_starts_even_ahead_of_the_scheduled_time(): void
    {
        Event::fake([DraftStateChanged::class]);
        $this->draft->update(['start_date' => $this->start->copy()->addHour()]);

        $this->pick($this->players[0], $this->interests[0])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The host has not started this draft yet.');

        $this->assertSame(0, Selection::count());
        $this->assertNull($this->draft->fresh()->turn_started_at);
        Event::assertNotDispatched(DraftStateChanged::class);
    }

    public function test_a_draft_that_has_not_reached_its_start_time_is_reported_as_scheduled(): void
    {
        $this->draft->update(['start_date' => $this->start->copy()->addHour()]);

        $response = $this->state($this->players[0])
            ->assertOk()
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('current_player', null)
            ->assertJsonPath('turn_ends_at', null);

        $this->assertTime(3600, $response->json('starts_at'));
        $this->assertNull($this->draft->fresh()->turn_started_at);
    }

    public function test_a_draft_past_its_start_time_waits_for_the_host(): void
    {
        Event::fake([DraftStateChanged::class]);

        $this->state($this->players[0])
            ->assertOk()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('current_player', null)
            ->assertJsonPath('turn_ends_at', null);

        // Time passing, and people looking, never starts it: only the host can.
        $this->at(3600);
        $this->state($this->players[1])->assertJsonPath('status', 'waiting');
        $this->pick($this->players[0], $this->interests[0])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The host has not started this draft yet.');

        $this->assertSame(0, Selection::count());
        $this->assertNull($this->draft->fresh()->turn_started_at);
        Event::assertNotDispatched(DraftStateChanged::class);
    }

    // ------------------------------------------------------------------ the host starts the draft

    public function test_the_host_starts_the_draft_and_the_clock_runs(): void
    {
        Event::fake([DraftStateChanged::class]);

        // The host is not a participant here, and lands on the live page to watch.
        $this->startDraft()->assertRedirect(route('show.interests', $this->draft->id));

        $response = $this->state($this->players[2])
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('current_player.id', $this->players[0]->id)
            ->assertJsonPath('time_limit', self::LIMIT);

        $this->assertTime(self::LIMIT, $response->json('turn_ends_at'));
        $this->assertTime(0, $response->json('server_time'));
        $this->assertTrue($this->draft->fresh()->turn_started_at->equalTo($this->start));
        Event::assertDispatchedTimes(DraftStateChanged::class, 1);
        Event::assertDispatched(DraftStateChanged::class, fn ($event) => $event->broadcastWith()['status'] === 'in_progress');
    }

    public function test_only_the_host_can_start_the_draft(): void
    {
        $this->actingAs($this->players[0])->post(route('start.draft', $this->draft->id))->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('start.draft', $this->draft->id))->assertForbidden();

        $this->assertNull($this->draft->fresh()->turn_started_at);
    }

    public function test_nobody_can_pick_until_the_host_has_started_the_draft(): void
    {
        // Even the first player, well past the start time, gets nowhere before the host starts.
        $this->pick($this->players[0], $this->interests[0])->assertStatus(409);
        $this->assertSame(0, Selection::count());

        $this->startDraft();
        $this->pick($this->players[0], $this->interests[0])->assertOk();
    }

    public function test_the_host_can_start_before_the_scheduled_time(): void
    {
        $this->draft->update(['start_date' => $this->start->copy()->addHour()]);
        $this->state($this->players[0])->assertJsonPath('status', 'scheduled');

        $this->startDraft()->assertSessionHasNoErrors();

        // The schedule is only a schedule: once the host starts, picking is on.
        $response = $this->state($this->players[0])
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('current_player.id', $this->players[0]->id);
        $this->assertTime(3600, $response->json('starts_at'));
        $this->assertTime(self::LIMIT, $response->json('turn_ends_at'));
        $this->pick($this->players[0], $this->interests[0])->assertOk();
    }

    public function test_the_host_cannot_start_until_everyone_has_joined_and_has_a_place(): void
    {
        $lastTeam = Team::where('draft_id', $this->draft->id)->where('selection_no', 3);

        $lastTeam->update(['user_id' => null]);
        $this->startDraft()->assertSessionHasErrors(['start' => 'Every invited participant must join before picking starts.']);

        $lastTeam->update(['user_id' => $this->players[2]->id, 'selection_no' => null]);
        $this->startDraft()->assertSessionHasErrors(['start' => 'Every participant must have a selection order before picking starts.']);

        $this->assertNull($this->draft->fresh()->turn_started_at);
    }

    public function test_a_draft_without_items_cannot_be_started(): void
    {
        Interest::where('draft_id', $this->draft->id)->delete();

        $this->startDraft()->assertSessionHasErrors(['start' => 'This draft has no items to select.']);

        $this->assertNull($this->draft->fresh()->turn_started_at);
    }

    public function test_starting_twice_changes_nothing(): void
    {
        $this->startDraft()->assertSessionHasNoErrors();
        Event::fake([DraftStateChanged::class]);

        $this->at(10);
        $this->startDraft()->assertSessionHasNoErrors();

        $this->assertTrue($this->draft->fresh()->turn_started_at->equalTo($this->start));
        Event::assertNotDispatched(DraftStateChanged::class);
    }

    // ------------------------------------------------------------------ the turn timer

    public function test_a_turn_is_not_skipped_before_its_time_is_up(): void
    {
        $this->startDraft();

        $this->at(self::LIMIT - 1);
        $this->state($this->players[1])
            ->assertJsonPath('current_player.id', $this->players[0]->id)
            ->assertJsonPath('last_skip', null);

        $this->assertSame(0, TurnSkip::count());
    }

    public function test_a_turn_is_skipped_when_its_time_is_up(): void
    {
        $this->startDraft();
        Event::fake([DraftStateChanged::class]);

        $this->at(self::LIMIT);
        $response = $this->state($this->players[2])
            ->assertOk()
            ->assertJsonPath('current_player.id', $this->players[1]->id)
            ->assertJsonPath('last_skip.player_id', $this->players[0]->id)
            ->assertJsonPath('pick_count', 0)
            ->assertJsonPath('turn_number', 1);

        // The next participant's clock starts when the skip is recorded.
        $this->assertTime(self::LIMIT + self::LIMIT, $response->json('turn_ends_at'));

        $skip = TurnSkip::sole();
        $this->assertSame($this->draft->id, $skip->draft_id);
        $this->assertSame($this->players[0]->id, $skip->team->user_id);
        Event::assertDispatchedTimes(DraftStateChanged::class, 1);
    }

    public function test_only_one_turn_is_skipped_per_check_however_long_everyone_was_away(): void
    {
        $this->startDraft();

        $this->at(3600);
        $this->state($this->players[1])
            ->assertJsonPath('current_player.id', $this->players[1]->id)
            ->assertJsonPath('turn_number', 1);
        $this->assertSame(1, TurnSkip::count());

        // Their clock starts fresh, so looking again straight away changes nothing.
        $this->state($this->players[2])
            ->assertJsonPath('current_player.id', $this->players[1]->id)
            ->assertJsonPath('turn_number', 1);
        $this->assertSame(1, TurnSkip::count());
    }

    public function test_a_late_pick_is_refused_and_the_turn_is_skipped(): void
    {
        $this->startDraft();

        $this->at(self::LIMIT + 1);
        $this->pick($this->players[0], $this->interests[0])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Your time ran out, so your turn was skipped.');

        $this->assertSame(0, Selection::count());
        $this->assertSame($this->players[0]->id, TurnSkip::sole()->team->user_id);

        // The turn has moved on to the next participant.
        $this->pick($this->players[1], $this->interests[0])->assertOk();
    }

    public function test_the_next_participant_can_pick_as_soon_as_the_turn_before_them_lapses(): void
    {
        $this->startDraft();

        $this->at(self::LIMIT + 1);
        $this->pick($this->players[1], $this->interests[0])->assertOk();

        $this->assertSame(1, Selection::count());
        $this->assertSame(1, TurnSkip::count());
    }

    public function test_a_pick_restarts_the_clock_for_the_next_participant(): void
    {
        $this->startDraft();

        $this->at(30);
        $response = $this->pick($this->players[0], $this->interests[0])->assertOk();
        $this->assertTime(30 + self::LIMIT, $response->json('state.turn_ends_at'));

        // 59 seconds into their turn: still theirs.
        $this->at(30 + self::LIMIT - 1);
        $this->state($this->players[2])->assertJsonPath('current_player.id', $this->players[1]->id);
        $this->assertSame(0, TurnSkip::count());

        // 60 seconds in: skipped.
        $this->at(30 + self::LIMIT);
        $this->state($this->players[2])->assertJsonPath('current_player.id', $this->players[2]->id);
        $this->assertSame(1, TurnSkip::count());
    }

    public function test_a_skip_is_only_reported_until_something_else_happens(): void
    {
        $this->startDraft();

        $this->at(self::LIMIT);
        $this->state($this->players[1])->assertJsonPath('last_skip.player_id', $this->players[0]->id);

        $this->pick($this->players[1], $this->interests[0])
            ->assertOk()
            ->assertJsonPath('state.last_skip', null)
            ->assertJsonPath('state.last_pick.player_id', $this->players[1]->id);
    }

    public function test_skipped_turns_do_not_use_up_items(): void
    {
        $this->startDraft();

        // Player 1 is skipped, then four picks fill the four items.
        $this->at(self::LIMIT);
        $this->state($this->players[0])->assertJsonPath('turn_number', 1);
        foreach ([1, 2, 0, 1] as $i => $player) {
            $this->pick($this->players[$player], $this->interests[$i])->assertOk();
        }

        $this->state($this->players[0])
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('pick_count', 4)
            ->assertJsonPath('turn_number', 5)
            ->assertJsonPath('current_player', null)
            ->assertJsonPath('turn_ends_at', null);
    }

    public function test_no_clock_runs_once_the_draft_is_complete(): void
    {
        $this->startDraft();
        foreach ([0, 1, 2, 0] as $i => $player) {
            $this->pick($this->players[$player], $this->interests[$i])->assertOk();
        }

        $this->at(7200);
        $this->state($this->players[1])->assertJsonPath('status', 'complete');

        $this->assertSame(0, TurnSkip::count());
    }

    public function test_the_version_only_ever_goes_up(): void
    {
        $versions = [];
        $versions[] = $this->state($this->players[0])->json('version'); // waiting for the host
        $this->startDraft();
        $versions[] = $this->state($this->players[0])->json('version'); // started
        $this->at(10);
        $versions[] = $this->pick($this->players[0], $this->interests[0])->json('state.version');
        $this->at(10 + self::LIMIT);
        $versions[] = $this->state($this->players[2])->json('version'); // skipped
        $versions[] = $this->pick($this->players[2], $this->interests[1])->json('state.version');

        $this->assertSame($versions, collect($versions)->sort()->unique()->values()->all());
    }

    public function test_a_turn_can_only_be_skipped_once(): void
    {
        $team = Team::where('draft_id', $this->draft->id)->first();
        $attributes = ['draft_id' => $this->draft->id, 'team_id' => $team->id, 'turn_number' => 0];

        TurnSkip::create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);
        TurnSkip::create($attributes);
    }

    public function test_outsiders_cannot_move_the_clock(): void
    {
        $this->startDraft();
        $outsider = User::factory()->create();

        $this->at(self::LIMIT + 1);
        $this->state($outsider)->assertForbidden();
        $this->pick($outsider, $this->interests[0])->assertForbidden();

        $this->assertSame(0, TurnSkip::count());
        $this->assertTrue($this->draft->fresh()->turn_started_at->equalTo($this->start));
    }

    public function test_a_skip_is_broadcast_to_the_draft_channel(): void
    {
        $this->startDraft();
        Event::fake([DraftStateChanged::class]);

        $this->at(self::LIMIT);
        $this->state($this->players[1])->assertOk();

        Event::assertDispatched(DraftStateChanged::class, function (DraftStateChanged $event) {
            $state = $event->broadcastWith();

            return $state['current_player']['id'] === $this->players[1]->id
                && $state['last_skip']['player_id'] === $this->players[0]->id;
        });
    }
}
