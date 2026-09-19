<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Interest;
use App\Models\Selection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DraftConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Draft $draft;

    protected function setUp(): void
    {
        parent::setUp();

        // Uploads go to a throwaway disk, never the real storage/app/public.
        Storage::fake('public');

        $this->owner = User::factory()->create();
        $this->draft = Draft::factory()->create(['user_id' => $this->owner->id]);
    }

    private function teamFor(User $user, int $selectionNo, ?Draft $draft = null): Team
    {
        return Team::factory()->create([
            'draft_id' => ($draft ?? $this->draft)->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'selection_no' => $selectionNo,
        ]);
    }

    public function test_the_creator_becomes_the_draft_owner(): void
    {
        $this->actingAs($this->owner)->post(route('create.draft'), [
            'name' => 'Spring yard sale',
            'title' => 'Pick your plots',
            'no_interests' => 3,
            'no_teams' => 2,
            'timer' => 30,
            'start_date' => '2026-10-01 10:00:00',
        ])->assertRedirect();

        $this->assertSame($this->owner->id, Draft::where('name', 'Spring yard sale')->sole()->user_id);
    }

    public function test_owner_can_set_the_selection_order(): void
    {
        $first = $this->teamFor(User::factory()->create(), 1);
        $second = $this->teamFor(User::factory()->create(), 2);

        $this->actingAs($this->owner)
            ->post(route('store.selection.order', $this->draft->id), [
                'selection_numbers' => [$first->id => 2, $second->id => 1],
            ])
            ->assertRedirect(route('draft.created', $this->draft->id));

        $this->assertSame(2, $first->fresh()->selection_no);
        $this->assertSame(1, $second->fresh()->selection_no);
    }

    public function test_other_users_cannot_change_the_selection_order(): void
    {
        $team = $this->teamFor(User::factory()->create(), 1);

        $this->actingAs(User::factory()->create())
            ->post(route('store.selection.order', $this->draft->id), [
                'selection_numbers' => [$team->id => 5],
            ])
            ->assertForbidden();

        $this->assertSame(1, $team->fresh()->selection_no);
    }

    public function test_other_users_cannot_add_participants_or_items(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('invite.teams', $this->draft->id), ['emails' => ['new@example.com']])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('store.interests', $this->draft->id), ['items' => ['Plot A']])
            ->assertForbidden();

        $this->assertSame(0, Team::count());
        $this->assertSame(0, Interest::count());
    }

    public function test_other_users_cannot_open_the_configuration_forms(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('add.interests.form', [$this->draft->id, 3]))->assertForbidden();
        $this->get(route('add.teams.form', [$this->draft->id, 3]))->assertForbidden();
        $this->get(route('show.selection.order', $this->draft->id))->assertForbidden();
    }

    public function test_owner_can_add_participants_and_items(): void
    {
        $this->actingAs($this->owner)
            ->post(route('invite.teams', $this->draft->id), ['emails' => ['new@example.com']])
            ->assertRedirect(route('invitations.sent', $this->draft->id));

        $this->actingAs($this->owner)
            ->post(route('store.interests', $this->draft->id), [
                'items' => ['Plot A'],
                'item_images' => [UploadedFile::fake()->image('plot.png')],
            ])
            ->assertRedirect();

        $this->assertSame(1, Team::where('draft_id', $this->draft->id)->where('email', 'new@example.com')->count());
        $this->assertSame(1, Interest::where('draft_id', $this->draft->id)->where('name', 'Plot A')->count());
    }

    public function test_a_draft_cannot_be_changed_once_picking_has_started(): void
    {
        $team = $this->teamFor(User::factory()->create(), 1);
        $interest = Interest::factory()->create(['draft_id' => $this->draft->id]);
        Selection::create([
            'interest_id' => $interest->id,
            'team_id' => $team->id,
            'draft_id' => $this->draft->id,
            'selected' => $interest->name,
            'is_selected' => true,
        ]);

        $this->actingAs($this->owner)
            ->post(route('store.selection.order', $this->draft->id), [
                'selection_numbers' => [$team->id => 9],
            ])
            ->assertForbidden();
        $this->actingAs($this->owner)
            ->post(route('invite.teams', $this->draft->id), ['emails' => ['late@example.com']])
            ->assertForbidden();
        $this->actingAs($this->owner)
            ->post(route('store.interests', $this->draft->id), ['items' => ['Late item']])
            ->assertForbidden();

        $this->assertSame(1, $team->fresh()->selection_no);
        $this->assertSame(1, Team::count());
        $this->assertSame(1, Interest::count());
    }

    public function test_a_draft_without_an_owner_cannot_be_changed_by_anyone(): void
    {
        $legacy = Draft::factory()->create(['user_id' => null]);

        $this->actingAs($this->owner)
            ->post(route('invite.teams', $legacy->id), ['emails' => ['new@example.com']])
            ->assertForbidden();
    }

    public function test_the_selection_order_cannot_touch_participants_of_another_draft(): void
    {
        $mine = $this->teamFor(User::factory()->create(), 1);
        $otherDraft = Draft::factory()->create();
        $theirs = $this->teamFor(User::factory()->create(), 1, $otherDraft);

        $this->actingAs($this->owner)
            ->post(route('store.selection.order', $this->draft->id), [
                'selection_numbers' => [$mine->id => 2, $theirs->id => 1],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(1, $mine->fresh()->selection_no);
        $this->assertSame(1, $theirs->fresh()->selection_no);
    }

    public function test_the_selection_order_must_cover_every_participant(): void
    {
        $first = $this->teamFor(User::factory()->create(), 1);
        $this->teamFor(User::factory()->create(), 2);

        $this->actingAs($this->owner)
            ->post(route('store.selection.order', $this->draft->id), [
                'selection_numbers' => [$first->id => 3],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(1, $first->fresh()->selection_no);
    }

    public function test_selection_numbers_must_be_unique_even_when_written_differently(): void
    {
        $first = $this->teamFor(User::factory()->create(), 1);
        $second = $this->teamFor(User::factory()->create(), 2);

        $this->actingAs($this->owner)
            ->post(route('store.selection.order', $this->draft->id), [
                'selection_numbers' => [$first->id => '1', $second->id => '01'],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(2, $second->fresh()->selection_no);
    }

    public function test_an_email_can_only_be_invited_once_per_draft(): void
    {
        $this->actingAs($this->owner)
            ->post(route('invite.teams', $this->draft->id), ['emails' => ['dup@example.com']])
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->post(route('invite.teams', $this->draft->id), ['emails' => ['DUP@example.com']])
            ->assertSessionHasErrors('emails.0');

        $this->actingAs($this->owner)
            ->post(route('invite.teams', $this->draft->id), ['emails' => ['a@example.com', 'A@example.com']])
            ->assertSessionHasErrors();

        $this->assertSame(1, Team::where('draft_id', $this->draft->id)->count());
    }

    public function test_the_start_time_is_read_in_the_creators_timezone(): void
    {
        $this->actingAs($this->owner)->post(route('create.draft'), [
            'name' => 'Lagos draft',
            'title' => 'Pick your plots',
            'no_interests' => 3,
            'no_teams' => 2,
            'timer' => 30,
            'start_date' => '2026-10-01T10:00',
            'timezone' => 'Africa/Lagos',
        ])->assertRedirect();

        // 10:00 in Lagos (UTC+1) is 09:00 UTC, which is how it is stored.
        $this->assertSame('2026-10-01 09:00:00', Draft::where('name', 'Lagos draft')->sole()->start_date->format('Y-m-d H:i:s'));
    }

    public function test_without_a_timezone_the_start_time_is_read_in_the_app_timezone(): void
    {
        $this->actingAs($this->owner)->post(route('create.draft'), [
            'name' => 'No zone draft',
            'title' => 'Pick your plots',
            'no_interests' => 3,
            'no_teams' => 2,
            'timer' => 30,
            'start_date' => '2026-10-01T10:00',
        ])->assertRedirect();

        $this->assertSame('2026-10-01 10:00:00', Draft::where('name', 'No zone draft')->sole()->start_date->format('Y-m-d H:i:s'));
    }

    public function test_an_invalid_start_time_or_timezone_is_rejected(): void
    {
        $valid = [
            'name' => 'Bad input draft',
            'title' => 'Pick your plots',
            'no_interests' => 3,
            'no_teams' => 2,
            'timer' => 30,
        ];

        $this->actingAs($this->owner)
            ->post(route('create.draft'), $valid + ['start_date' => 'not a date'])
            ->assertSessionHasErrors('start_date');
        $this->actingAs($this->owner)
            ->post(route('create.draft'), $valid + ['start_date' => '2026-10-01T10:00', 'timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');

        $this->assertSame(0, Draft::where('name', 'Bad input draft')->count());
    }

    public function test_a_draft_cannot_be_changed_once_its_clock_has_started(): void
    {
        $team = $this->teamFor(User::factory()->create(), 1);
        $this->draft->update(['turn_started_at' => now()]);

        $this->actingAs($this->owner)
            ->post(route('store.selection.order', $this->draft->id), [
                'selection_numbers' => [$team->id => 9],
            ])
            ->assertForbidden();
        $this->actingAs($this->owner)
            ->post(route('invite.teams', $this->draft->id), ['emails' => ['late@example.com']])
            ->assertForbidden();

        $this->assertSame(1, $team->fresh()->selection_no);
        $this->assertSame(1, Team::count());
    }

    public function test_only_the_owner_and_participants_can_see_the_draft_details(): void
    {
        $participant = User::factory()->create();
        $this->teamFor($participant, 1);

        $this->actingAs($this->owner)->get(route('draft.details', $this->draft->id))->assertOk();
        $this->actingAs($participant)->get(route('draft.details', $this->draft->id))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('draft.details', $this->draft->id))->assertForbidden();
    }

    public function test_only_the_host_can_start_the_draft(): void
    {
        $participant = User::factory()->create();
        $this->teamFor($participant, 1);

        $this->actingAs($participant)->post(route('start.draft', $this->draft->id))->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('start.draft', $this->draft->id))->assertForbidden();

        $this->assertNull($this->draft->fresh()->turn_started_at);
    }

    public function test_the_host_sees_the_start_button_and_participants_see_the_picking_link(): void
    {
        $participant = User::factory()->create();
        $this->teamFor($participant, 1);

        // The host is not a participant here: they start the draft and can watch it.
        $this->actingAs($this->owner)
            ->get(route('draft.details', $this->draft->id))
            ->assertOk()
            ->assertSee('Start Draft')
            ->assertSee('Watch the draft')
            ->assertDontSee('Go to picking page');

        $this->actingAs($participant)
            ->get(route('draft.details', $this->draft->id))
            ->assertOk()
            ->assertSee('Waiting for the host to start the draft.')
            ->assertSee('Go to picking page')
            ->assertDontSee('Watch the draft')
            ->assertDontSee('Start Draft');
    }

    public function test_a_host_who_is_also_a_participant_gets_the_picking_link_not_the_watch_link(): void
    {
        $this->teamFor($this->owner, 1);

        $this->actingAs($this->owner)
            ->get(route('draft.details', $this->draft->id))
            ->assertOk()
            ->assertSee('Start Draft')
            ->assertSee('Go to picking page')
            ->assertDontSee('Watch the draft');
    }

    public function test_the_start_button_goes_away_once_the_draft_has_started(): void
    {
        $this->draft->update(['turn_started_at' => now()]);

        $this->actingAs($this->owner)
            ->get(route('draft.details', $this->draft->id))
            ->assertOk()
            ->assertSee('The draft has started.')
            ->assertDontSee('Start Draft');
    }

    public function test_only_the_host_sees_the_email_of_a_participant_who_has_not_joined(): void
    {
        $participant = User::factory()->create();
        $this->teamFor($participant, 1);
        Team::factory()->invited()->create(['draft_id' => $this->draft->id, 'email' => 'invitee@example.com']);

        $this->actingAs($this->owner)
            ->get(route('draft.details', $this->draft->id))
            ->assertSee('invitee@example.com');

        $this->actingAs($participant)
            ->get(route('draft.details', $this->draft->id))
            ->assertDontSee('invitee@example.com')
            ->assertSee('Invited (not joined yet)');
    }

    public function test_only_the_owner_can_see_the_invitation_links(): void
    {
        $participant = User::factory()->create();
        $team = $this->teamFor($participant, 1);

        $this->actingAs($this->owner)
            ->get(route('invitations.sent', $this->draft->id))
            ->assertOk()
            ->assertSee($team->token);
        $this->actingAs($participant)->get(route('invitations.sent', $this->draft->id))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('invitations.sent', $this->draft->id))->assertForbidden();
    }

    public function test_only_the_owner_can_see_the_draft_created_page(): void
    {
        $participant = User::factory()->create();
        $this->teamFor($participant, 1);

        // The host is pointed at the details page, which is where the draft gets started.
        $this->actingAs($this->owner)
            ->get(route('draft.created', $this->draft->id))
            ->assertOk()
            ->assertSee(route('draft.details', $this->draft->id));
        $this->actingAs($participant)->get(route('draft.created', $this->draft->id))->assertForbidden();
    }

    public function test_the_owner_relationship_points_at_the_owner(): void
    {
        $this->assertTrue($this->draft->creator->is($this->owner));
    }
}
