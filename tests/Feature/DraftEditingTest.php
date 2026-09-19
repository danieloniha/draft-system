<?php

namespace Tests\Feature;

use App\Events\DraftStateChanged;
use App\Models\Draft;
use App\Models\Interest;
use App\Models\Selection;
use App\Models\Team;
use App\Models\User;
use App\Services\DraftEditor;
use App\Services\DraftPickService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

/**
 * The host can change anything about a draft until they start it: its settings,
 * its items, its participants and their order. Afterwards, nothing.
 */
class DraftEditingTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    private Draft $draft;

    private User $host;

    /** @var Collection<int, User> Participants, in selection order. */
    private Collection $players;

    /** @var Collection<int, Interest> */
    private Collection $interests;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-19 12:00:00');
        Storage::fake('public');

        [$this->draft, $this->players, $this->interests] = $this->buildDraft(3, 3, [
            'name' => 'Yard Sale',
            'title' => 'Pick your plots',
            'selection_time_limit' => 60,
            'start_date' => Carbon::parse('2026-09-20 10:00:00'),
        ]);
        $this->host = $this->draft->creator;
    }

    private function asHost()
    {
        return $this->actingAs($this->host);
    }

    private function editUrl(): string
    {
        return route('draft.edit', $this->draft->id);
    }

    private function teamOf(User $user): Team
    {
        return Team::where('draft_id', $this->draft->id)->where('user_id', $user->id)->firstOrFail();
    }

    private function startDraft(): void
    {
        $this->asHost()->post(route('start.draft', $this->draft->id));
    }

    private function state(User $user)
    {
        return $this->actingAs($user)->getJson(route('draft.state', $this->draft->id));
    }

    // ------------------------------------------------------------------ who can edit, and until when

    public function test_only_the_host_can_open_the_edit_page(): void
    {
        $this->asHost()->get($this->editUrl())
            ->assertOk()
            ->assertSee('Yard Sale')
            ->assertSee('Pick your plots')
            ->assertSee($this->interests[0]->name);

        $this->actingAs($this->players[0])->get($this->editUrl())->assertForbidden();
        $this->actingAs(User::factory()->create())->get($this->editUrl())->assertForbidden();

        auth()->logout();
        $this->get($this->editUrl())->assertRedirect(route('login'));
    }

    public function test_the_edit_page_is_closed_once_the_draft_has_started(): void
    {
        $this->draft->update(['turn_started_at' => now()]);

        $this->asHost()->get($this->editUrl())->assertForbidden();
    }

    public function test_a_draft_that_already_has_selections_counts_as_started(): void
    {
        Selection::create([
            'interest_id' => $this->interests[0]->id,
            'team_id' => $this->teamOf($this->players[0])->id,
            'draft_id' => $this->draft->id,
            'selected' => $this->interests[0]->name,
            'is_selected' => true,
        ]);

        $this->assertNull($this->draft->fresh()->turn_started_at);
        $this->assertTrue($this->draft->fresh()->hasStarted());
        $this->asHost()->get($this->editUrl())->assertForbidden();
    }

    public function test_a_draft_has_not_started_until_the_clock_runs_or_a_selection_exists(): void
    {
        $this->assertFalse($this->draft->hasStarted());

        $this->draft->update(['turn_started_at' => now()]);
        $this->assertTrue($this->draft->fresh()->hasStarted());
    }

    public function test_a_draft_without_an_owner_cannot_be_edited(): void
    {
        $this->draft->update(['user_id' => null]);

        $this->asHost()->get($this->editUrl())->assertForbidden();
    }

    public function test_the_edit_page_says_what_is_stopping_the_host_from_starting(): void
    {
        $this->asHost()->get($this->editUrl())->assertOk()->assertDontSee('Before you can start');

        Team::factory()->invited()->create(['draft_id' => $this->draft->id, 'email' => 'late@example.com']);

        $this->asHost()->get($this->editUrl())
            ->assertSee('Before you can start')
            ->assertSee('1 invited participant(s) have not joined yet.')
            ->assertSee('1 participant(s) still need a place in the selection order.');
    }

    public function test_the_edit_page_suggests_the_next_free_number_for_someone_not_yet_placed(): void
    {
        $newcomer = Team::factory()->invited()->create(['draft_id' => $this->draft->id, 'email' => 'late@example.com']);

        $this->asHost()->get($this->editUrl())
            ->assertViewHas('suggested', fn ($suggested) => $suggested->all() === [$newcomer->id => 4]);
    }

    // ------------------------------------------------------------------ settings

    public function test_the_host_can_change_the_settings(): void
    {
        $this->asHost()
            ->patch(route('draft.update', $this->draft->id), [
                'name' => 'Renamed sale',
                'title' => 'A new description',
                'timer' => 90,
                'start_date' => '2026-10-05T18:30',
                'timezone' => 'Africa/Lagos',
            ])
            ->assertRedirect($this->editUrl())
            ->assertSessionHas('status', 'Settings saved.');

        $draft = $this->draft->fresh();
        $this->assertSame('Renamed sale', $draft->name);
        $this->assertSame('A new description', $draft->title);
        $this->assertSame(90, $draft->selection_time_limit);
        // 18:30 in Lagos (UTC+1) is 17:30 UTC, which is how it is stored.
        $this->assertSame('2026-10-05 17:30:00', $draft->start_date->format('Y-m-d H:i:s'));
    }

    public function test_settings_are_validated(): void
    {
        $valid = ['name' => 'Ok', 'title' => 'Ok', 'timer' => 30, 'start_date' => '2026-10-05T18:30'];

        foreach ([
            ['name' => ''],
            ['title' => ''],
            ['name' => str_repeat('x', 256)],
            ['timer' => 0],
            ['timer' => 'soon'],
            ['start_date' => 'not a date'],
            ['timezone' => 'Mars/Olympus'],
        ] as $bad) {
            $this->asHost()->patch(route('draft.update', $this->draft->id), $bad + $valid)->assertSessionHasErrors(array_key_first($bad));
        }

        $draft = $this->draft->fresh();
        $this->assertSame('Yard Sale', $draft->name);
        $this->assertSame(60, $draft->selection_time_limit);
    }

    public function test_other_users_cannot_change_the_settings(): void
    {
        $payload = ['name' => 'Hijacked', 'title' => 'x', 'timer' => 5, 'start_date' => '2026-10-05T18:30'];

        $this->actingAs($this->players[0])->patch(route('draft.update', $this->draft->id), $payload)->assertForbidden();
        $this->actingAs(User::factory()->create())->patch(route('draft.update', $this->draft->id), $payload)->assertForbidden();

        $this->assertSame('Yard Sale', $this->draft->fresh()->name);
    }

    public function test_the_settings_are_locked_once_the_draft_has_started(): void
    {
        $this->startDraft();

        $this->asHost()
            ->patch(route('draft.update', $this->draft->id), ['name' => 'Too late', 'title' => 'x', 'timer' => 5, 'start_date' => '2026-10-05T18:30'])
            ->assertForbidden();

        $this->assertSame('Yard Sale', $this->draft->fresh()->name);
        $this->assertSame(60, $this->draft->fresh()->selection_time_limit);
    }

    public function test_changing_the_scheduled_start_changes_what_participants_see(): void
    {
        $this->state($this->players[0])->assertJsonPath('status', 'scheduled');

        $this->asHost()->patch(route('draft.update', $this->draft->id), [
            'name' => 'Yard Sale', 'title' => 'x', 'timer' => 60, 'start_date' => '2026-09-19T08:00', 'timezone' => 'UTC',
        ]);

        $this->state($this->players[0])->assertJsonPath('status', 'waiting');
    }

    // ------------------------------------------------------------------ items

    public function test_the_host_can_add_an_item_from_the_edit_page(): void
    {
        $this->asHost()
            ->post(route('store.interests', $this->draft->id), [
                'items' => ['Brass lamp'],
                'item_images' => [UploadedFile::fake()->image('lamp.png')],
                'return_to' => 'edit',
            ])
            ->assertRedirect($this->editUrl())
            ->assertSessionHas('status', 'Item added.');

        $item = Interest::where('draft_id', $this->draft->id)->where('name', 'Brass lamp')->sole();
        $this->assertSame(4, Interest::where('draft_id', $this->draft->id)->count());
        Storage::disk('public')->assertExists($item->image_path);
    }

    public function test_the_creation_wizard_still_moves_on_to_inviting_people(): void
    {
        $this->asHost()
            ->post(route('store.interests', $this->draft->id), ['items' => ['Brass lamp']])
            ->assertRedirect(route('add.teams.form', ['draft_id' => $this->draft->id, 'no_of_teams' => $this->draft->no_of_teams]));
    }

    public function test_return_to_only_accepts_known_targets(): void
    {
        $this->asHost()
            ->post(route('store.interests', $this->draft->id), ['items' => ['Brass lamp'], 'return_to' => 'https://evil.example'])
            ->assertSessionHasErrors('return_to');

        $this->assertSame(3, Interest::where('draft_id', $this->draft->id)->count());
    }

    public function test_the_host_can_rename_an_item(): void
    {
        $item = $this->interests[0];

        $this->asHost()
            ->patch(route('draft.items.update', [$this->draft->id, $item->id]), ['name' => 'Renamed item'])
            ->assertRedirect($this->editUrl())
            ->assertSessionHas('status', 'Item saved.');

        $this->assertSame('Renamed item', $item->fresh()->name);
    }

    public function test_the_host_can_replace_and_remove_an_items_photo(): void
    {
        $item = $this->interests[0];
        $url = route('draft.items.update', [$this->draft->id, $item->id]);

        $this->asHost()->patch($url, ['name' => $item->name, 'image' => UploadedFile::fake()->image('first.png')]);
        $first = $item->fresh()->image_path;
        Storage::disk('public')->assertExists($first);

        // A new photo replaces the old one, and the old file goes.
        $this->asHost()->patch($url, ['name' => $item->name, 'image' => UploadedFile::fake()->image('second.png')]);
        $second = $item->fresh()->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);

        // Saving without touching the photo keeps it.
        $this->asHost()->patch($url, ['name' => 'Renamed']);
        $this->assertSame($second, $item->fresh()->image_path);
        Storage::disk('public')->assertExists($second);

        // Ticking "remove photo" clears it.
        $this->asHost()->patch($url, ['name' => 'Renamed', 'remove_image' => 1]);
        $this->assertNull($item->fresh()->image_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_the_host_can_remove_an_item_and_its_photo(): void
    {
        $item = $this->interests[0];
        $this->asHost()->patch(route('draft.items.update', [$this->draft->id, $item->id]), [
            'name' => $item->name, 'image' => UploadedFile::fake()->image('photo.png'),
        ]);
        $path = $item->fresh()->image_path;

        $this->asHost()
            ->delete(route('draft.items.destroy', [$this->draft->id, $item->id]))
            ->assertRedirect($this->editUrl())
            ->assertSessionHas('status', 'Item removed.');

        $this->assertNull(Interest::find($item->id));
        $this->assertSame(2, Interest::where('draft_id', $this->draft->id)->count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_an_item_of_another_draft_cannot_be_changed_or_removed(): void
    {
        $foreign = Interest::factory()->create(['name' => 'Theirs']);

        $this->asHost()->patch(route('draft.items.update', [$this->draft->id, $foreign->id]), ['name' => 'Mine now'])->assertNotFound();
        $this->asHost()->delete(route('draft.items.destroy', [$this->draft->id, $foreign->id]))->assertNotFound();

        $this->assertSame('Theirs', $foreign->fresh()->name);
    }

    public function test_item_names_and_photos_are_validated(): void
    {
        $item = $this->interests[0];
        $url = route('draft.items.update', [$this->draft->id, $item->id]);

        $this->asHost()->patch($url, ['name' => ''])->assertSessionHasErrors('name');
        $this->asHost()->patch($url, ['name' => 'Ok', 'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('image');

        $this->assertNotSame('', $item->fresh()->name);
        $this->assertNull($item->fresh()->image_path);
    }

    public function test_items_are_locked_once_the_draft_has_started(): void
    {
        $this->startDraft();
        $item = $this->interests[0];
        $name = $item->name;

        $this->asHost()->post(route('store.interests', $this->draft->id), ['items' => ['Late item'], 'return_to' => 'edit'])->assertForbidden();
        $this->asHost()->patch(route('draft.items.update', [$this->draft->id, $item->id]), ['name' => 'Late rename'])->assertForbidden();
        $this->asHost()->delete(route('draft.items.destroy', [$this->draft->id, $item->id]))->assertForbidden();

        $this->assertSame(3, Interest::where('draft_id', $this->draft->id)->count());
        $this->assertSame($name, $item->fresh()->name);
    }

    // ------------------------------------------------------------------ participants

    public function test_the_host_can_invite_someone_from_the_edit_page(): void
    {
        $this->asHost()
            ->post(route('invite.teams', $this->draft->id), ['emails' => ['new@example.com'], 'return_to' => 'edit'])
            ->assertRedirect($this->editUrl());

        $team = Team::where('draft_id', $this->draft->id)->where('email', 'new@example.com')->sole();
        $this->assertNull($team->user_id);
        $this->assertNull($team->selection_no);
        $this->assertSame(32, strlen($team->token));
    }

    public function test_the_creation_wizard_still_ends_on_the_invitation_links_page(): void
    {
        $this->asHost()
            ->post(route('invite.teams', $this->draft->id), ['emails' => ['new@example.com']])
            ->assertRedirect(route('invitations.sent', $this->draft->id));
    }

    public function test_someone_already_in_the_draft_cannot_be_invited_again(): void
    {
        $this->asHost()
            ->post(route('invite.teams', $this->draft->id), ['emails' => [strtoupper($this->players[0]->email)], 'return_to' => 'edit'])
            ->assertSessionHasErrors('emails.0');

        $this->assertSame(3, Team::where('draft_id', $this->draft->id)->count());
    }

    public function test_the_host_can_remove_a_participant_and_the_order_closes_the_gap(): void
    {
        $removed = $this->teamOf($this->players[1]);

        $this->asHost()
            ->delete(route('draft.participants.destroy', [$this->draft->id, $removed->id]))
            ->assertRedirect($this->editUrl())
            ->assertSessionHas('status', 'Participant removed.');

        $this->assertNull(Team::find($removed->id));
        $this->assertSame(1, $this->teamOf($this->players[0])->selection_no);
        $this->assertSame(2, $this->teamOf($this->players[2])->selection_no, 'the third participant moves up to second');

        // They are out: no access to the draft any more, and their invitation link is dead.
        $this->state($this->players[1])->assertForbidden();
        $this->actingAs($this->players[1])->post(route('join.draft'), ['token' => $removed->token])->assertSessionHasErrors('token');
    }

    public function test_removing_someone_who_has_not_joined_leaves_the_order_alone(): void
    {
        $invited = Team::factory()->invited()->create(['draft_id' => $this->draft->id, 'email' => 'late@example.com']);

        $this->asHost()->delete(route('draft.participants.destroy', [$this->draft->id, $invited->id]))->assertRedirect($this->editUrl());

        $this->assertNull(Team::find($invited->id));
        foreach ([1, 2, 3] as $i => $selectionNo) {
            $this->assertSame($selectionNo, $this->teamOf($this->players[$i])->selection_no);
        }
    }

    public function test_a_participant_of_another_draft_cannot_be_removed(): void
    {
        $foreign = Team::factory()->create();

        $this->asHost()->delete(route('draft.participants.destroy', [$this->draft->id, $foreign->id]))->assertNotFound();

        $this->assertNotNull(Team::find($foreign->id));
    }

    public function test_participants_are_locked_once_the_draft_has_started(): void
    {
        $this->startDraft();
        $team = $this->teamOf($this->players[0]);

        $this->asHost()->post(route('invite.teams', $this->draft->id), ['emails' => ['late@example.com'], 'return_to' => 'edit'])->assertForbidden();
        $this->asHost()->delete(route('draft.participants.destroy', [$this->draft->id, $team->id]))->assertForbidden();

        $this->assertSame(3, Team::where('draft_id', $this->draft->id)->count());
    }

    public function test_the_host_can_start_only_once_a_new_participant_has_joined_and_is_placed(): void
    {
        $this->asHost()->post(route('invite.teams', $this->draft->id), ['emails' => ['late@example.com'], 'return_to' => 'edit']);

        $this->asHost()->post(route('start.draft', $this->draft->id))
            ->assertSessionHasErrors(['start' => 'Every invited participant must join before picking starts.']);
        $this->assertNull($this->draft->fresh()->turn_started_at);

        // Change of plan: take them off again, and the host can start.
        $this->asHost()->delete(route('draft.participants.destroy', [$this->draft->id, Team::where('email', 'late@example.com')->value('id')]));
        $this->asHost()->post(route('start.draft', $this->draft->id))->assertSessionHasNoErrors();
        $this->assertNotNull($this->draft->fresh()->turn_started_at);
    }

    // ------------------------------------------------------------------ order

    public function test_the_host_can_change_the_selection_order_from_the_edit_page(): void
    {
        $order = [
            $this->teamOf($this->players[2])->id => 1,
            $this->teamOf($this->players[0])->id => 2,
            $this->teamOf($this->players[1])->id => 3,
        ];

        $this->asHost()
            ->post(route('store.selection.order', $this->draft->id), ['selection_numbers' => $order, 'return_to' => 'edit'])
            ->assertRedirect($this->editUrl())
            ->assertSessionHas('status', 'Selection order saved.');

        $players = $this->state($this->players[0])->assertOk()->json('players');
        $this->assertSame(
            [$this->players[2]->id, $this->players[0]->id, $this->players[1]->id],
            array_column($players, 'id')
        );
    }

    public function test_a_bad_order_from_the_edit_page_is_refused_with_a_message(): void
    {
        $teams = Team::where('draft_id', $this->draft->id)->orderBy('id')->pluck('id');

        $this->asHost()
            ->post(route('store.selection.order', $this->draft->id), [
                'selection_numbers' => [$teams[0] => 1, $teams[1] => 1, $teams[2] => 2],
                'return_to' => 'edit',
            ])
            ->assertSessionHasErrors(['selection_numbers' => 'Selection numbers must be unique.']);

        $this->asHost()
            ->post(route('store.selection.order', $this->draft->id), [
                'selection_numbers' => [$teams[0] => 1, $teams[1] => 2],
                'return_to' => 'edit',
            ])
            ->assertSessionHasErrors(['selection_numbers' => 'Selection numbers must be provided for every participant in this draft.']);
    }

    // ------------------------------------------------------------------ participants already on the picking page

    public function test_an_edit_is_announced_to_everyone_on_the_picking_page(): void
    {
        $before = $this->state($this->players[0])->json('layout');
        Event::fake([DraftStateChanged::class]);

        $this->asHost()->patch(route('draft.items.update', [$this->draft->id, $this->interests[0]->id]), ['name' => 'Renamed item']);

        Event::assertDispatched(DraftStateChanged::class, function (DraftStateChanged $event) use ($before) {
            return $event->broadcastOn()->name === 'private-draft.'.$this->draft->id
                && $event->broadcastWith()['layout'] !== $before;
        });
    }

    public function test_the_layout_changes_with_the_items_and_players_but_not_with_play(): void
    {
        $initial = $this->state($this->players[0])->json('layout');
        $this->assertSame($initial, $this->state($this->players[1])->json('layout'), 'the same for everyone');

        $this->asHost()->patch(route('draft.items.update', [$this->draft->id, $this->interests[0]->id]), ['name' => 'Renamed item']);
        $afterItem = $this->state($this->players[0])->json('layout');
        $this->assertNotSame($initial, $afterItem);

        $this->asHost()->delete(route('draft.participants.destroy', [$this->draft->id, $this->teamOf($this->players[2])->id]));
        $afterPlayer = $this->state($this->players[0])->json('layout');
        $this->assertNotSame($afterItem, $afterPlayer);

        // Once the draft is running it is fixed, so play never changes it.
        $this->startDraft();
        $this->actingAs($this->players[0])->postJson(route('select.interest', $this->draft->id), ['interest_id' => $this->interests[1]->id])->assertOk();
        $this->assertSame($afterPlayer, $this->state($this->players[1])->json('layout'));
    }

    public function test_a_failure_while_announcing_never_undoes_a_saved_edit_or_deletes_its_photo(): void
    {
        // Announcing happens after the edit is committed and is best-effort.
        $this->partialMock(DraftPickService::class, fn ($mock) => $mock
            ->shouldReceive('state')->andThrow(new \RuntimeException('the database went away')));

        $item = $this->interests[0];
        $this->asHost()
            ->patch(route('draft.items.update', [$this->draft->id, $item->id]), [
                'name' => 'Saved anyway',
                'image' => UploadedFile::fake()->image('kept.png'),
            ])
            ->assertRedirect($this->editUrl());

        $item->refresh();
        $this->assertSame('Saved anyway', $item->name);
        Storage::disk('public')->assertExists($item->image_path);
    }

    public function test_an_edit_that_leaves_the_draft_not_ready_is_saved_without_an_announcement(): void
    {
        Event::fake([DraftStateChanged::class]);

        $this->asHost()
            ->post(route('invite.teams', $this->draft->id), ['emails' => ['late@example.com'], 'return_to' => 'edit'])
            ->assertRedirect($this->editUrl());

        $this->assertSame(1, Team::where('email', 'late@example.com')->count());
        Event::assertNotDispatched(DraftStateChanged::class);
    }

    // ------------------------------------------------------------------ getting to the edit page

    public function test_the_host_is_offered_the_edit_page_only_before_the_draft_starts(): void
    {
        $details = route('draft.details', $this->draft->id);

        $this->asHost()->get($details)->assertOk()->assertSee('Edit session')->assertSee($this->editUrl());
        $this->actingAs($this->players[0])->get($details)->assertOk()->assertDontSee('Edit session');

        $this->draft->update(['turn_started_at' => now()]);
        $this->asHost()->get($details)->assertOk()->assertDontSee('Edit session');
    }

    public function test_the_details_page_counts_the_real_participants_and_items(): void
    {
        $this->asHost()->post(route('store.interests', $this->draft->id), ['items' => ['Extra one', 'Extra two'], 'return_to' => 'edit']);

        // The counts entered when the session was created (2 and 3) are only a starting point.
        $this->draft->update(['no_of_teams' => 99, 'no_of_interests' => 99]);

        $this->asHost()->get(route('draft.details', $this->draft->id))
            ->assertSee('<strong>Participants:</strong> 3', false)
            ->assertSee('<strong>Items:</strong> 5', false);
    }

    public function test_the_editor_rechecks_under_the_lock_so_a_stale_permission_check_cannot_slip_through(): void
    {
        // A request loads the draft and is allowed to edit it...
        $stale = Draft::find($this->draft->id);
        $this->assertFalse($stale->hasStarted());

        // ...but before it gets to write, the host presses Start.
        $this->startDraft();
        $this->assertTrue($this->draft->fresh()->hasStarted());

        // Every kind of edit must now be refused, whatever the caller checked earlier.
        $editor = app(DraftEditor::class);
        $refused = 0;
        foreach ([
            fn () => $editor->updateSettings($stale, ['name' => 'Too late']),
            fn () => $editor->addItems($stale, [['name' => 'Too late', 'image' => null]]),
            fn () => $editor->updateItem($stale, $this->interests[0]->id, 'Too late', null, false),
            fn () => $editor->removeItem($stale, $this->interests[0]->id),
            fn () => $editor->addParticipants($stale, ['late@example.com']),
            fn () => $editor->removeParticipant($stale, $this->teamOf($this->players[0])->id),
            fn () => $editor->setOrder($stale, [$this->teamOf($this->players[0])->id => 1, $this->teamOf($this->players[1])->id => 2, $this->teamOf($this->players[2])->id => 3]),
        ] as $edit) {
            try {
                $edit();
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
                $refused++;
            }
        }

        $this->assertSame(7, $refused, 'every kind of edit is refused');
        $draft = $this->draft->fresh();
        $this->assertSame('Yard Sale', $draft->name);
        $this->assertSame(3, Interest::where('draft_id', $draft->id)->count());
        $this->assertSame(3, Team::where('draft_id', $draft->id)->count());
    }

    public function test_the_dashboard_links_to_the_edit_page_for_sessions_the_host_can_still_change(): void
    {
        $this->asHost()->get(route('dashboard'))->assertOk()->assertSee($this->editUrl());
        $this->actingAs($this->players[0])->get(route('dashboard'))->assertOk()->assertDontSee($this->editUrl());

        $this->draft->update(['turn_started_at' => now()]);
        $this->asHost()->get(route('dashboard'))->assertOk()->assertDontSee($this->editUrl());
    }

    public function test_the_session_created_page_links_to_the_edit_page(): void
    {
        $this->asHost()->get(route('draft.created', $this->draft->id))->assertOk()->assertSee($this->editUrl());
    }
}
