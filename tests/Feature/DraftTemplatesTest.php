<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating a draft: the template (Giveaway/Bidding), visibility
 * (Private/Public) and order mode (host-decided/fcfs/random) a host picks,
 * and the participant limit that applies to both visibilities.
 */
class DraftTemplatesTest extends TestCase
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
            'type' => 'giveaway',
            'visibility' => 'private',
            'order_mode' => 'host_decided',
            'participant_limit' => 100,
        ], $overrides);
    }

    public function test_the_host_chooses_the_template_visibility_and_order_mode(): void
    {
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload([
            'name' => 'Live auction',
            'type' => 'bidding',
            'visibility' => 'public',
            'order_mode' => 'random',
            'participant_limit' => 25,
        ]))->assertRedirect();

        $draft = Draft::where('name', 'Live auction')->sole();
        $this->assertSame('bidding', $draft->type);
        $this->assertSame('public', $draft->visibility);
        $this->assertSame('random', $draft->order_mode);
        $this->assertSame(25, $draft->participant_limit);
        $this->assertTrue($draft->isBidding());
        $this->assertTrue($draft->isPublic());
    }

    public function test_a_public_draft_gets_a_join_link_and_a_private_one_does_not(): void
    {
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload(['name' => 'Public one', 'visibility' => 'public']));
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload(['name' => 'Private one', 'visibility' => 'private']));

        $this->assertNotNull(Draft::where('name', 'Public one')->sole()->public_token);
        $this->assertNull(Draft::where('name', 'Private one')->sole()->public_token);
    }

    public function test_a_public_draft_skips_pre_invites_and_goes_straight_to_finished(): void
    {
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload([
            'name' => 'Public giveaway', 'visibility' => 'public', 'no_teams' => 0,
        ]));
        $draft = Draft::where('name', 'Public giveaway')->sole();

        $this->actingAs($this->owner)
            ->post(route('store.interests', $draft->id), ['items' => ['Prize']])
            ->assertRedirect(route('draft.created', $draft->id));

        $this->assertSame(0, $draft->teams()->count());
        $this->assertSame(0, $draft->no_of_teams);
    }

    public function test_a_private_draft_still_goes_through_inviting_people(): void
    {
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload([
            'name' => 'Private giveaway', 'visibility' => 'private', 'no_teams' => 2,
        ]));
        $draft = Draft::where('name', 'Private giveaway')->sole();

        $this->actingAs($this->owner)
            ->post(route('store.interests', $draft->id), ['items' => ['Prize']])
            ->assertRedirect(route('add.teams.form', ['draft_id' => $draft->id, 'no_of_teams' => $draft->no_of_teams]));
    }

    public function test_no_teams_may_be_zero_but_not_negative(): void
    {
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload(['no_teams' => 0]))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload(['no_teams' => -1]))->assertSessionHasErrors('no_teams');
    }

    public function test_the_description_is_optional(): void
    {
        // A blank but submitted field (what an optional text input actually sends) is treated
        // the same as leaving it out entirely.
        $this->actingAs($this->owner)
            ->post(route('create.draft'), $this->payload(['name' => 'No description', 'title' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Draft::where('name', 'No description')->sole()->title);

        $payload = $this->payload(['name' => 'No description at all']);
        unset($payload['title']);
        $this->actingAs($this->owner)->post(route('create.draft'), $payload)->assertSessionHasNoErrors();

        $this->assertNull(Draft::where('name', 'No description at all')->sole()->title);
    }

    public function test_type_visibility_and_order_mode_must_be_one_of_the_known_values(): void
    {
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload(['type' => 'auction-house']))->assertSessionHasErrors('type');
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload(['visibility' => 'semi-public']))->assertSessionHasErrors('visibility');
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload(['order_mode' => 'snake']))->assertSessionHasErrors('order_mode');

        $this->assertSame(0, Draft::count());
    }

    public function test_the_participant_limit_must_be_between_one_and_a_hundred(): void
    {
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload(['participant_limit' => 0]))->assertSessionHasErrors('participant_limit');
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload(['participant_limit' => 101]))->assertSessionHasErrors('participant_limit');
        $this->actingAs($this->owner)->post(route('create.draft'), $this->payload(['participant_limit' => 'lots']))->assertSessionHasErrors('participant_limit');

        $this->assertSame(0, Draft::count());
    }

    public function test_all_four_fields_are_required(): void
    {
        foreach (['type', 'visibility', 'order_mode', 'participant_limit'] as $missing) {
            $payload = $this->payload();
            unset($payload[$missing]);

            $this->actingAs($this->owner)
                ->post(route('create.draft'), $payload)
                ->assertSessionHasErrors($missing);
        }

        $this->assertSame(0, Draft::count());
    }

    public function test_existing_drafts_default_to_giveaway_private_host_decided(): void
    {
        // A row written the way the old code wrote it, bypassing the factory's (new) explicit
        // values entirely, so this actually exercises the migration's column defaults.
        $id = \Illuminate\Support\Facades\DB::table('drafts')->insertGetId([
            'user_id' => $this->owner->id,
            'name' => 'Legacy draft',
            'title' => 'x',
            'no_of_interests' => 1,
            'no_of_teams' => 1,
            'selection_time_limit' => 60,
            'start_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $draft = Draft::findOrFail($id);

        $this->assertSame('giveaway', $draft->type);
        $this->assertSame('private', $draft->visibility);
        $this->assertSame('host_decided', $draft->order_mode);
        $this->assertSame(100, $draft->participant_limit);
        $this->assertNull($draft->public_token);
    }
}
