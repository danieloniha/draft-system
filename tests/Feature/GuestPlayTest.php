<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Team;
use App\Models\User;
use App\Services\DraftJoinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

/**
 * Playing a public session needs no account. Someone who opens its link without being logged
 * in just gives a name and plays as a guest: a real user row (so turns, policies and
 * broadcasting all work as usual) with no email and no password of their own. Hosting a
 * session and joining a private one (whose invitation is tied to an email address) still
 * need an account.
 */
class GuestPlayTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    private function publicDraft(array $attributes = []): Draft
    {
        $draft = Draft::factory()->create(['visibility' => 'public', ...$attributes]);
        $draft->ensurePublicToken();

        return $draft->fresh();
    }

    /** Guests only: every draft the factory makes also makes a host user, which is not what is being counted. */
    private function guestCount(): int
    {
        return User::where('is_guest', true)->count();
    }

    private function invitation(array $draftAttributes = [], string $email = 'invited@example.com'): Team
    {
        $draft = Draft::factory()->create($draftAttributes);

        return Team::factory()->invited()->create(['draft_id' => $draft->id, 'email' => $email]);
    }

    // ------------------------------------------------------------------ public link

    public function test_someone_with_no_account_can_join_a_public_draft_with_just_a_name(): void
    {
        $draft = $this->publicDraft();

        $this->post(route('public.join', $draft->public_token), ['username' => 'Sam', 'email' => 'p@example.com'])
            ->assertRedirect(route('draft.details', $draft->id));

        $guest = User::where('username', 'Sam')->sole();
        $this->assertTrue($guest->isGuest());
        $this->assertNull($guest->email);
        $this->assertAuthenticatedAs($guest);
        $this->assertSame($guest->id, Team::where('draft_id', $draft->id)->sole()->user_id);
    }

    public function test_the_guest_can_then_see_the_session_they_joined(): void
    {
        $draft = $this->publicDraft();
        $this->post(route('public.join', $draft->public_token), ['username' => 'Sam', 'email' => 'p@example.com']);

        $this->get(route('draft.details', $draft->id))->assertOk()->assertSee('Sam');
        $this->getJson(route('draft.state', $draft->id))->assertStatus(422); // not ready to show, but allowed in
    }

    public function test_a_name_is_required_and_kept_short(): void
    {
        $draft = $this->publicDraft();

        $this->post(route('public.join', $draft->public_token), ['email' => 'p@example.com'])->assertSessionHasErrors('username');
        $this->post(route('public.join', $draft->public_token), ['username' => str_repeat('x', 51), 'email' => 'p@example.com'])->assertSessionHasErrors('username');

        $this->assertSame(0, $this->guestCount());
        $this->assertSame(0, Team::count());
    }

    public function test_two_guests_may_share_a_name(): void
    {
        $draft = $this->publicDraft();

        $this->post(route('public.join', $draft->public_token), ['username' => 'Sam', 'email' => 'p@example.com']);
        auth()->logout();
        $this->post(route('public.join', $draft->public_token), ['username' => 'Sam', 'email' => 'p@example.com']);

        $this->assertSame(2, User::where('username', 'Sam')->count());
        $this->assertSame(2, Team::where('draft_id', $draft->id)->count());
    }

    public function test_a_join_that_fails_leaves_no_guest_account_behind(): void
    {
        $full = $this->publicDraft(['participant_limit' => 1]);
        Team::factory()->create(['draft_id' => $full->id]);
        $started = $this->publicDraft(['turn_started_at' => now()]);

        $this->post(route('public.join', $full->public_token), ['username' => 'Late', 'email' => 'p@example.com'])
            ->assertRedirect(route('public.join.form', $full->public_token))
            ->assertSessionHasErrors(['join' => 'This draft is full.']);
        $this->post(route('public.join', $started->public_token), ['username' => 'Late', 'email' => 'p@example.com'])
            ->assertSessionHasErrors('join');

        $this->assertSame(0, $this->guestCount(), 'no stray guest accounts');
        $this->assertGuest();
    }

    public function test_a_stranger_is_not_mistaken_for_a_participant_because_of_an_unjoined_seat(): void
    {
        // An invited-but-not-joined seat has user_id NULL, which must never match "nobody".
        $draft = $this->publicDraft();
        Team::factory()->invited()->create(['draft_id' => $draft->id, 'email' => 'someone@example.com']);

        $this->get(route('public.join.form', $draft->public_token))
            ->assertOk()
            ->assertDontSee('You have already joined this draft.')
            ->assertSee('Join this draft');
    }

    public function test_fcfs_order_is_given_to_guests_as_they_arrive(): void
    {
        $draft = $this->publicDraft(['order_mode' => 'fcfs']);

        $this->post(route('public.join', $draft->public_token), ['username' => 'First', 'email' => 'p@example.com']);
        auth()->logout();
        $this->post(route('public.join', $draft->public_token), ['username' => 'Second', 'email' => 'p@example.com']);

        $order = Team::where('draft_id', $draft->id)->orderBy('selection_no')->with('user')->get()->pluck('user.username')->all();
        $this->assertSame(['First', 'Second'], $order);
    }

    // ------------------------------------------------------------------ private invitation: needs an account

    public function test_a_private_invitation_needs_an_account_so_a_stranger_is_sent_to_log_in(): void
    {
        $team = $this->invitation();

        $this->get(route('join.draft.form', ['token' => $team->token]))->assertRedirect(route('login'));
        $this->post(route('join.draft'), ['token' => $team->token])->assertRedirect(route('login'));

        $this->assertSame(0, $this->guestCount(), 'no guest account is made for a private session');
        $this->assertNull($team->fresh()->user_id);
    }

    public function test_after_logging_in_they_are_carried_on_to_the_invitation(): void
    {
        $team = $this->invitation();
        $account = User::factory()->create(['email' => 'invited@example.com']);
        $link = route('join.draft.form', ['token' => $team->token]);

        $this->get($link)->assertRedirect(route('login'));

        $this->post(route('login'), ['email' => 'invited@example.com', 'password' => 'password'])
            ->assertRedirect($link);
        $this->assertAuthenticatedAs($account);
    }

    public function test_signing_up_from_an_invitation_carries_on_to_it_too(): void
    {
        $team = $this->invitation();
        $link = route('join.draft.form', ['token' => $team->token]);

        $this->get($link)->assertRedirect(route('login'));

        $this->post(route('register'), [
            'username' => 'Newcomer',
            'email' => 'invited@example.com',
            'password' => 'a-good-password-1',
            'password_confirmation' => 'a-good-password-1',
        ])->assertRedirect($link);

        // ...and from there the seat is theirs, since the email is the one that was invited.
        $this->post(route('join.draft'), ['token' => $team->token])
            ->assertRedirect(route('draft.details', $team->draft_id));
        $this->assertSame(User::where('email', 'invited@example.com')->sole()->id, $team->fresh()->user_id);
    }

    public function test_a_guest_is_asked_to_sign_up_for_a_private_invitation(): void
    {
        $team = $this->invitation();
        $guest = User::factory()->guest()->create();

        $this->actingAs($guest)->get(route('join.draft.form', ['token' => $team->token]))
            ->assertRedirect(route('register'))
            ->assertSessionHas('status', 'Private sessions need an account. Sign up with the email address you were invited on to join.');

        $this->actingAs($guest)->post(route('join.draft'), ['token' => $team->token])
            ->assertRedirect(route('register'));

        $this->assertNull($team->fresh()->user_id);
    }

    public function test_the_invitation_page_says_which_session_it_is_for(): void
    {
        $team = $this->invitation(['name' => 'Saturday Yard Sale']);

        $this->actingAs(User::factory()->create())
            ->get(route('join.draft.form', ['token' => $team->token]))
            ->assertOk()
            ->assertSee('Join Saturday Yard Sale')
            ->assertDontSee('Your name');
    }

    public function test_an_invitation_needs_a_real_token(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('join.draft'), ['token' => 'not-a-real-token'])
            ->assertSessionHasErrors('token');
    }

    public function test_a_seat_someone_else_already_holds_cannot_be_taken_from_the_link(): void
    {
        $team = $this->invitation(email: 'invited@example.com');
        $owner = User::factory()->create();
        $team->update(['user_id' => $owner->id]);

        // Even with the invited email address: the seat is taken.
        $this->actingAs(User::factory()->create(['email' => 'invited@example.com']))
            ->post(route('join.draft'), ['token' => $team->token])
            ->assertSessionHasErrors(['token' => 'This invitation has already been used.']);
        $this->assertSame($owner->id, $team->fresh()->user_id);

        // The holder submitting it again is harmless.
        $this->actingAs($owner)->post(route('join.draft'), ['token' => $team->token])
            ->assertRedirect(route('draft.details', $team->draft_id));
    }

    public function test_a_real_account_still_needs_the_invited_email(): void
    {
        $team = $this->invitation(email: 'invited@example.com');

        $this->actingAs(User::factory()->create(['email' => 'someone-else@example.com']))
            ->post(route('join.draft'), ['token' => $team->token])
            ->assertSessionHasErrors(['token' => 'This invitation belongs to a different email address.']);
        $this->assertNull($team->fresh()->user_id);

        $invited = User::factory()->create(['email' => 'INVITED@example.com']);
        $this->actingAs($invited)->post(route('join.draft'), ['token' => $team->token])
            ->assertRedirect(route('draft.details', $team->draft_id));
        $this->assertSame($invited->id, $team->fresh()->user_id);
    }

    public function test_the_join_service_reports_whether_the_seat_is_really_theirs(): void
    {
        $team = $this->invitation();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $service = app(DraftJoinService::class);

        $this->assertTrue($service->joinPrivate($team, $first));
        $this->assertTrue($service->joinPrivate($team->fresh(), $first), 'a repeat is harmless');
        $this->assertFalse($service->joinPrivate($team->fresh(), $second), 'someone else already has it');
        $this->assertSame($first->id, $team->fresh()->user_id);
    }

    // ------------------------------------------------------------------ playing

    public function test_a_guest_takes_their_turn_like_anyone_else(): void
    {
        [$draft, $players, $interests] = $this->buildDraft(2, 3);
        $players[0]->forceFill(['is_guest' => true, 'email' => null])->save();
        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));

        $this->actingAs($players[0]->fresh())
            ->postJson(route('select.interest', $draft->id), ['interest_id' => $interests[0]->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        // ...and is held to the same rules: it is now the other player's turn.
        $this->actingAs($players[0]->fresh())
            ->postJson(route('select.interest', $draft->id), ['interest_id' => $interests[1]->id])
            ->assertForbidden();
    }

    public function test_a_guest_participant_may_listen_to_the_draft_channel(): void
    {
        [$draft, $players] = $this->buildDraft(2, 3);
        $players[0]->forceFill(['is_guest' => true, 'email' => null])->save();

        $broadcaster = app(\Illuminate\Broadcasting\BroadcastManager::class)->driver();
        $authorize = (fn () => $this->channels)->call($broadcaster)['draft.{draftId}'];

        $this->assertTrue((bool) $authorize($players[0]->fresh(), $draft->id));
        $this->assertFalse((bool) $authorize(User::factory()->guest()->create(), $draft->id), 'a guest who is not in it');
    }

    // ------------------------------------------------------------------ hosting still needs an account

    public function test_a_guest_cannot_start_creating_a_session_or_open_the_profile(): void
    {
        $guest = User::factory()->guest()->create();

        foreach ([
            fn () => $this->actingAs($guest)->get(route('view.draft')),
            fn () => $this->actingAs($guest)->get(route('create.draft.giveaway.form')),
            fn () => $this->actingAs($guest)->get(route('create.draft.bidding.form')),
            fn () => $this->actingAs($guest)->post(route('create.draft'), ['name' => 'x']),
            fn () => $this->actingAs($guest)->get(route('profile.edit')),
        ] as $attempt) {
            $attempt()->assertRedirect(route('register'))->assertSessionHas('status');
        }

        $this->assertSame(0, Draft::count());
    }

    public function test_a_guest_can_still_reach_login_and_sign_up(): void
    {
        $guest = User::factory()->guest()->create();

        $this->actingAs($guest)->get(route('register'))->assertOk();
        $this->actingAs($guest)->get(route('login'))->assertOk();
    }

    public function test_someone_with_an_account_is_still_sent_away_from_login(): void
    {
        $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect(route('dashboard'));
    }

    public function test_a_guest_signing_up_keeps_the_seats_they_already_hold(): void
    {
        $draft = $this->publicDraft();
        $this->post(route('public.join', $draft->public_token), ['username' => 'Sam', 'email' => 'p@example.com']);
        $guest = User::where('username', 'Sam')->sole();
        $usersBefore = User::count();

        $this->post(route('register'), [
            'username' => 'Samuel',
            'email' => 'sam@example.com',
            'password' => 'a-good-password-1',
            'password_confirmation' => 'a-good-password-1',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame($usersBefore, User::count(), 'the same row was upgraded, not a second user created');
        $account = $guest->fresh();
        $this->assertFalse($account->isGuest());
        $this->assertSame('sam@example.com', $account->email);
        $this->assertSame('Samuel', $account->username);
        $this->assertAuthenticatedAs($account);
        $this->assertSame($account->id, Team::where('draft_id', $draft->id)->sole()->user_id, 'still in the session');
    }

    // ------------------------------------------------------------------ the pages

    public function test_the_guest_dashboard_offers_an_account_not_a_session(): void
    {
        $guest = User::factory()->guest()->create(['username' => 'Sam']);

        $this->actingAs($guest)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Welcome, Sam')
            ->assertSee('Create an account')
            ->assertDontSee('Create a session');

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Create a session')
            ->assertDontSee('Create an account');
    }

    public function test_login_and_sign_up_link_to_each_other(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('register'))
            ->assertSee('Create an account')
            ->assertSee('no account needed');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee(route('login'))
            ->assertSee('Log in');
    }

    public function test_the_auth_pages_use_the_site_stylesheet(): void
    {
        foreach (['login', 'register', 'password.request'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('/assets/style.css')
                ->assertSee('auth-container');
        }
    }

    // ------------------------------------------------------------------ abuse

    public function test_creating_guest_accounts_is_rate_limited(): void
    {
        $draft = $this->publicDraft();

        for ($i = 0; $i < 20; $i++) {
            $this->post(route('public.join', $draft->public_token), ['email' => 'p@example.com'])->assertSessionHasErrors('username');
        }

        $this->post(route('public.join', $draft->public_token), ['username' => 'Sam', 'email' => 'p@example.com'])->assertStatus(429);
        $this->assertSame(0, $this->guestCount());
    }
}
