<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating a draft starts with choosing a template — two boxes, the same
 * pattern as "Create a session" / "Join a session" on the dashboard — and
 * each template then gets its own dedicated creation form, not one shared
 * form with a type dropdown.
 */
class DraftTypeChooserTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test draft',
            'title' => 'Pick something',
            'no_interests' => 3,
            'no_teams' => 2,
            'timer' => 30,
            'start_date' => '2026-10-01T10:00',
            'visibility' => 'private',
            'order_mode' => 'host_decided',
            'participant_limit' => 100,
        ], $overrides);
    }

    public function test_the_chooser_offers_giveaway_and_bidding(): void
    {
        $this->actingAs($this->owner)
            ->get(route('view.draft'))
            ->assertOk()
            ->assertSee('Giveaway')
            ->assertSee('Bidding')
            ->assertSee(route('create.draft.giveaway.form'))
            ->assertSee(route('create.draft.bidding.form'));
    }

    public function test_the_chooser_requires_login(): void
    {
        $this->get(route('view.draft'))->assertRedirect(route('login'));
    }

    public function test_the_giveaway_form_is_giveaway_specific(): void
    {
        $this->actingAs($this->owner)
            ->get(route('create.draft.giveaway.form'))
            ->assertOk()
            ->assertSee('Create a Giveaway')
            ->assertSee('Turn Timer')
            ->assertSee('Selection Order')
            ->assertDontSee('Bid Timer')
            ->assertDontSee('Item Order')
            ->assertDontSee('<select id="type"', false);
    }

    public function test_the_bidding_form_is_bidding_specific(): void
    {
        $this->actingAs($this->owner)
            ->get(route('create.draft.bidding.form'))
            ->assertOk()
            ->assertSee('Create a Bidding Auction')
            ->assertSee('Bid Timer')
            ->assertSee('Item Order')
            ->assertDontSee('Turn Timer')
            ->assertDontSee('Selection Order')
            ->assertDontSee('<select id="type"', false);
    }

    public function test_both_forms_require_login(): void
    {
        $this->get(route('create.draft.giveaway.form'))->assertRedirect(route('login'));
        $this->get(route('create.draft.bidding.form'))->assertRedirect(route('login'));
    }

    public function test_the_giveaway_form_submits_a_giveaway_draft(): void
    {
        $this->actingAs($this->owner)
            ->post(route('create.draft'), $this->payload(['name' => 'A giveaway', 'type' => 'giveaway']))
            ->assertRedirect();

        $this->assertSame('giveaway', Draft::where('name', 'A giveaway')->sole()->type);
    }

    public function test_the_bidding_form_submits_a_bidding_draft(): void
    {
        $this->actingAs($this->owner)
            ->post(route('create.draft'), $this->payload(['name' => 'An auction', 'type' => 'bidding']))
            ->assertRedirect();

        $this->assertSame('bidding', Draft::where('name', 'An auction')->sole()->type);
    }
}
