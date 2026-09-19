<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Interest;
use App\Models\Selection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function join(User $user, Draft $draft, int $selectionNo = 1): Team
    {
        return Team::factory()->create([
            'draft_id' => $draft->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'selection_no' => $selectionNo,
        ]);
    }

    public function test_a_new_user_is_told_they_have_no_sessions_yet(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your sessions')
            ->assertSee('You are not part of any session yet.');
    }

    public function test_the_host_can_find_their_session_again_and_reach_its_details(): void
    {
        $host = User::factory()->create();
        $draft = Draft::factory()->create(['user_id' => $host->id, 'name' => 'Saturday Yard Sale']);

        $this->actingAs($host)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Saturday Yard Sale')
            ->assertSee('Host')
            ->assertSee('Not started')
            ->assertSee(route('draft.details', $draft->id))
            ->assertSee(route('show.interests', $draft->id))
            ->assertSee('Watch');
    }

    public function test_a_participant_sees_the_sessions_they_are_in(): void
    {
        $participant = User::factory()->create();
        $draft = Draft::factory()->create(['name' => 'Plot Draft']);
        $this->join($participant, $draft);

        $this->actingAs($participant)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Plot Draft')
            ->assertSee('Participant')
            ->assertSee(route('draft.details', $draft->id))
            ->assertSee('Picking page');
    }

    public function test_someone_who_hosts_and_takes_part_is_labelled_as_both(): void
    {
        $user = User::factory()->create();
        $draft = Draft::factory()->create(['user_id' => $user->id]);
        $this->join($user, $draft);

        $this->actingAs($user)->get(route('dashboard'))->assertSee('Host and participant');
    }

    public function test_sessions_of_other_people_are_not_listed(): void
    {
        $user = User::factory()->create();
        Draft::factory()->create(['name' => 'Somebody Elses Draft']);
        $invitedOnly = Draft::factory()->create(['name' => 'Invited But Not Joined']);
        Team::factory()->invited()->create(['draft_id' => $invitedOnly->id, 'email' => $user->email]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Somebody Elses Draft')
            ->assertDontSee('Invited But Not Joined');
    }

    public function test_the_status_follows_the_draft_from_not_started_to_complete(): void
    {
        $host = User::factory()->create();
        $draft = Draft::factory()->create(['user_id' => $host->id]);
        $item = Interest::factory()->create(['draft_id' => $draft->id]);
        $team = $this->join(User::factory()->create(), $draft);

        $this->actingAs($host)->get(route('dashboard'))->assertSee('Not started');

        $draft->update(['turn_started_at' => now()]);
        $this->actingAs($host)->get(route('dashboard'))->assertSee('In progress')->assertDontSee('Not started');

        Selection::create([
            'interest_id' => $item->id,
            'team_id' => $team->id,
            'draft_id' => $draft->id,
            'selected' => $item->name,
            'is_selected' => true,
        ]);
        $this->actingAs($host)->get(route('dashboard'))->assertSee('Complete')->assertDontSee('In progress');
    }

    public function test_the_dashboard_requires_a_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
