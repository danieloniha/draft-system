<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

/**
 * A public draft's shareable link: anyone can open it and join themself (with an account,
 * or as a guest — see GuestPlayTest), up to the host's participant limit, until the draft starts.
 */
class PublicDraftTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    private function publicDraft(int $limit = 100, array $attributes = []): Draft
    {
        $draft = Draft::factory()->create([
            'visibility' => 'public',
            'participant_limit' => $limit,
            ...$attributes,
        ]);
        $draft->ensurePublicToken();

        return $draft->fresh();
    }

    public function test_a_logged_in_user_can_self_join_a_public_draft(): void
    {
        $draft = $this->publicDraft();
        $alice = User::factory()->create();

        $this->actingAs($alice)
            ->post(route('public.join', $draft->public_token))
            ->assertRedirect(route('draft.details', $draft->id));

        $team = Team::where('draft_id', $draft->id)->where('user_id', $alice->id)->sole();
        $this->assertSame($alice->email, $team->email);
        $this->assertNull($team->token, 'no personal invite token is needed for a self-joined seat');
    }

    public function test_joining_twice_is_harmless(): void
    {
        $draft = $this->publicDraft();
        $alice = User::factory()->create();

        $this->actingAs($alice)->post(route('public.join', $draft->public_token));
        $this->actingAs($alice)->post(route('public.join', $draft->public_token))->assertRedirect(route('draft.details', $draft->id));

        $this->assertSame(1, Team::where('draft_id', $draft->id)->where('user_id', $alice->id)->count());
    }

    public function test_someone_not_logged_in_is_not_sent_to_login_they_are_asked_for_a_name(): void
    {
        $draft = $this->publicDraft();

        // No login wall: the page loads and asks for a name instead. (Joining as a guest is
        // covered in GuestPlayTest.)
        $this->get(route('public.join.form', $draft->public_token))
            ->assertOk()
            ->assertSee('Your name')
            ->assertSee('Join this draft');

        // ...but a name is required, and nothing is created without one.
        $this->post(route('public.join', $draft->public_token))->assertSessionHasErrors('username');

        $this->assertSame(0, Team::where('draft_id', $draft->id)->count());
        $this->assertSame(0, User::where('is_guest', true)->count());
    }

    public function test_a_draft_that_was_never_public_has_no_link(): void
    {
        $draft = Draft::factory()->create(['visibility' => 'private']);
        $this->assertNull($draft->public_token);
    }

    public function test_a_draft_switched_back_to_private_can_no_longer_be_joined_through_its_old_link(): void
    {
        $draft = $this->publicDraft();
        $token = $draft->public_token;
        $draft->update(['visibility' => 'private']);

        $alice = User::factory()->create();
        $this->actingAs($alice)
            ->post(route('public.join', $token))
            ->assertRedirect(route('public.join.form', $token));

        $this->assertSame(0, Team::where('draft_id', $draft->id)->count());
    }

    public function test_the_join_page_explains_why_when_it_cannot_be_joined(): void
    {
        $draft = $this->publicDraft();
        $draft->update(['visibility' => 'private']);
        $alice = User::factory()->create();

        $this->actingAs($alice)
            ->get(route('public.join.form', $draft->public_token))
            ->assertOk()
            ->assertSee('This link is no longer active.');
    }

    public function test_the_link_closes_once_the_limit_is_reached(): void
    {
        $draft = $this->publicDraft(limit: 2);
        [$first, $second, $third] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];

        $this->actingAs($first)->post(route('public.join', $draft->public_token))->assertRedirect(route('draft.details', $draft->id));
        $this->actingAs($second)->post(route('public.join', $draft->public_token))->assertRedirect(route('draft.details', $draft->id));

        $this->actingAs($third)
            ->post(route('public.join', $draft->public_token))
            ->assertRedirect(route('public.join.form', $draft->public_token));
        $this->actingAs($third)
            ->get(route('public.join.form', $draft->public_token))
            ->assertSee('This draft is full.');

        $this->assertSame(2, Team::where('draft_id', $draft->id)->count());
        $this->assertSame(0, Team::where('draft_id', $draft->id)->where('user_id', $third->id)->count());
    }

    public function test_a_removed_participant_frees_their_slot(): void
    {
        $draft = $this->publicDraft(limit: 1);
        $first = User::factory()->create();
        $this->actingAs($first)->post(route('public.join', $draft->public_token));

        $second = User::factory()->create();
        $this->actingAs($second)
            ->post(route('public.join', $draft->public_token))
            ->assertRedirect(route('public.join.form', $draft->public_token));

        app(\App\Services\DraftEditor::class)->removeParticipant(
            $draft,
            Team::where('draft_id', $draft->id)->where('user_id', $first->id)->sole()->id
        );

        $this->actingAs($second)
            ->post(route('public.join', $draft->public_token))
            ->assertRedirect(route('draft.details', $draft->id));

        $this->assertSame(1, Team::where('draft_id', $draft->id)->count());
        $this->assertSame($second->id, Team::where('draft_id', $draft->id)->sole()->user_id);
    }

    public function test_joining_after_the_draft_has_started_is_refused(): void
    {
        $draft = $this->publicDraft();
        $draft->update(['turn_started_at' => now()]);
        $alice = User::factory()->create();

        $this->actingAs($alice)
            ->post(route('public.join', $draft->public_token))
            ->assertRedirect(route('public.join.form', $draft->public_token));
        $this->actingAs($alice)
            ->get(route('public.join.form', $draft->public_token))
            ->assertSee('already started');

        $this->assertSame(0, Team::where('draft_id', $draft->id)->count());
    }

    public function test_the_join_page_shows_the_draft_and_the_current_count(): void
    {
        $draft = $this->publicDraft(limit: 10, attributes: ['name' => 'Saturday Sale']);
        $this->actingAs(User::factory()->create())->post(route('public.join', $draft->public_token));
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->get(route('public.join.form', $draft->public_token))
            ->assertOk()
            ->assertSee('Saturday Sale')
            ->assertSee('1 / 10')
            ->assertSee('Join this draft');
    }

    public function test_someone_who_has_already_joined_sees_that_instead_of_a_join_button(): void
    {
        $draft = $this->publicDraft();
        $alice = User::factory()->create();
        $this->actingAs($alice)->post(route('public.join', $draft->public_token));

        $this->actingAs($alice)
            ->get(route('public.join.form', $draft->public_token))
            ->assertOk()
            ->assertSee('You have already joined this draft.')
            ->assertDontSee('Join this draft');
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('public.join.form', 'not-a-real-token'))
            ->assertNotFound();
    }
}
