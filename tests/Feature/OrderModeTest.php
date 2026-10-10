<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Interest;
use App\Models\Team;
use App\Models\User;
use App\Services\DraftEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

/**
 * order_mode decides how turn order (Giveaway) or item order (Bidding) is
 * settled: by the host, by who joined first, or by a shuffle at start.
 */
class OrderModeTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    // ------------------------------------------------------------------ Giveaway: participant order

    public function test_fcfs_assigns_the_next_number_as_each_private_invitee_accepts(): void
    {
        $draft = Draft::factory()->create(['order_mode' => 'fcfs']);
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        // Bob was invited first, but Alice accepts first — order follows acceptance, not invitation.
        $bobsInvite = Team::factory()->invited()->create(['draft_id' => $draft->id, 'email' => $bob->email]);
        $alicesInvite = Team::factory()->invited()->create(['draft_id' => $draft->id, 'email' => $alice->email]);

        $this->actingAs($alice)->post(route('join.draft'), ['token' => $alicesInvite->token]);
        $this->actingAs($bob)->post(route('join.draft'), ['token' => $bobsInvite->token]);

        $this->assertSame(1, $alicesInvite->fresh()->selection_no);
        $this->assertSame(2, $bobsInvite->fresh()->selection_no);
    }

    public function test_fcfs_assigns_the_next_number_as_each_public_joiner_arrives(): void
    {
        $draft = Draft::factory()->create(['order_mode' => 'fcfs', 'visibility' => 'public']);
        $draft->ensurePublicToken();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->post(route('public.join', $draft->public_token), ['email' => 'p@example.com']);
        $this->actingAs($bob)->post(route('public.join', $draft->public_token), ['email' => 'p@example.com']);

        $this->assertSame(1, Team::where('draft_id', $draft->id)->where('user_id', $alice->id)->sole()->selection_no);
        $this->assertSame(2, Team::where('draft_id', $draft->id)->where('user_id', $bob->id)->sole()->selection_no);
    }

    public function test_a_draft_with_fcfs_order_can_be_started_without_the_host_setting_anything(): void
    {
        [$draft, $players, $interests] = $this->buildDraft(3, 2, ['order_mode' => 'fcfs']);
        // buildDraft() assigns selection_no directly; simulate what fcfs join actually leaves behind.
        Team::where('draft_id', $draft->id)->orderBy('id')->get()->each(
            fn (Team $team, int $i) => $team->update(['selection_no' => $i + 1])
        );

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))
            ->assertRedirect(route('show.interests', $draft->id));

        $this->assertNotNull($draft->fresh()->turn_started_at);
    }

    public function test_random_shuffles_every_participant_exactly_once_at_start(): void
    {
        $draft = Draft::factory()->create(['order_mode' => 'random']);
        $teams = collect(range(1, 5))->map(fn () => Team::factory()->create(['draft_id' => $draft->id, 'selection_no' => null]));
        Interest::factory()->create(['draft_id' => $draft->id]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))->assertSessionHasNoErrors();

        $assigned = $teams->map(fn ($team) => $team->fresh()->selection_no)->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4, 5], $assigned, 'every number 1..N is used exactly once');
    }

    public function test_random_order_is_only_assigned_once(): void
    {
        $draft = Draft::factory()->create(['order_mode' => 'random']);
        $team = Team::factory()->create(['draft_id' => $draft->id, 'selection_no' => null]);
        Interest::factory()->create(['draft_id' => $draft->id]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));
        $firstAssignment = $team->fresh()->selection_no;

        // A second start (double click) must not shuffle again.
        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));
        $this->assertSame($firstAssignment, $team->fresh()->selection_no);
    }

    public function test_the_picking_page_is_viewable_before_start_under_random_even_though_nobody_has_a_number_yet(): void
    {
        [$draft, $players] = $this->buildDraft(2, 2, ['order_mode' => 'random', 'start_date' => now()->subHour()]);
        Team::where('draft_id', $draft->id)->update(['selection_no' => null]);

        $this->actingAs($players[0])
            ->getJson(route('draft.state', $draft->id))
            ->assertOk()
            ->assertJsonPath('status', 'waiting');
    }

    public function test_host_decided_still_requires_the_host_to_set_the_order_before_starting(): void
    {
        [$draft, $players] = $this->buildDraft(2, 2, ['order_mode' => 'host_decided']);
        Team::where('draft_id', $draft->id)->update(['selection_no' => null]);

        $this->actingAs($draft->creator)
            ->post(route('start.draft', $draft->id))
            ->assertSessionHasErrors(['start' => 'Every participant must have a selection order before picking starts.']);

        $this->assertNull($draft->fresh()->turn_started_at);
    }

    // ------------------------------------------------------------------ Bidding: item order

    public function test_bidding_fcfs_item_order_is_simply_the_order_items_were_added(): void
    {
        [$draft, $players] = $this->buildBiddingDraft(2, 0, ['order_mode' => 'fcfs']);
        $editor = app(DraftEditor::class);

        $editor->addItems($draft, [['name' => 'First', 'image' => null]]);
        $editor->addItems($draft, [['name' => 'Second', 'image' => null], ['name' => 'Third', 'image' => null]]);

        $items = Interest::where('draft_id', $draft->id)->orderBy('position')->pluck('name')->all();
        $this->assertSame(['First', 'Second', 'Third'], $items);
    }

    public function test_bidding_random_shuffles_every_item_exactly_once_at_start(): void
    {
        [$draft, $players, $interests] = $this->buildBiddingDraft(2, 5, ['order_mode' => 'random']);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))->assertSessionHasNoErrors();

        $positions = $interests->map(fn ($item) => $item->fresh()->position)->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4, 5], $positions);
    }

    public function test_bidding_host_decided_lets_the_host_rearrange_the_items(): void
    {
        [$draft, $players, $interests] = $this->buildBiddingDraft(2, 3, ['order_mode' => 'host_decided']);
        $editor = app(DraftEditor::class);

        $editor->setItemOrder($draft, [
            $interests[0]->id => 3,
            $interests[1]->id => 1,
            $interests[2]->id => 2,
        ]);

        $ordered = Interest::where('draft_id', $draft->id)->orderBy('position')->pluck('id')->all();
        $this->assertSame([$interests[1]->id, $interests[2]->id, $interests[0]->id], $ordered);
    }

    public function test_bidding_item_order_numbers_must_be_unique_and_cover_every_item(): void
    {
        [$draft, $players, $interests] = $this->buildBiddingDraft(2, 2, ['order_mode' => 'host_decided']);
        $editor = app(DraftEditor::class);

        try {
            $editor->setItemOrder($draft, [$interests[0]->id => 1, $interests[1]->id => 1]);
            $this->fail('expected a validation exception for duplicate positions');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('positions', $e->errors());
        }

        try {
            $editor->setItemOrder($draft, [$interests[0]->id => 1]);
            $this->fail('expected a validation exception for a missing item');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('positions', $e->errors());
        }
    }
}
