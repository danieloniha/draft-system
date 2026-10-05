<?php

namespace Tests\Feature;

use App\Events\DraftStateChanged;
use App\Models\Draft;
use App\Models\PayoutTier;
use App\Models\Team;
use App\Models\User;
use App\Services\DraftEditor;
use App\Services\DraftPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

/**
 * Giveaway "money" mode end to end: nobody picks anything — participants are
 * ranked the same way Giveaway already ranks them, the host's payout table
 * decides what each rank is paid, and starting the draft is a one-time,
 * live-broadcast reveal rather than a turn-based session.
 */
class MoneySplitFlowTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    // ------------------------------------------------------------------ creation

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cash prize',
            'title' => 'Payout draft',
            'no_interests' => 1,
            'no_teams' => 3,
            'timer' => 30,
            'start_date' => '2026-10-01T10:00',
            'type' => 'giveaway',
            'visibility' => 'private',
            'order_mode' => 'host_decided',
            'participant_limit' => 100,
        ], $overrides);
    }

    public function test_choosing_money_mode_redirects_to_the_payout_tiers_step(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('create.draft'), $this->payload(['giveaway_mode' => 'money']));

        $draft = Draft::where('name', 'Cash prize')->sole();
        $this->assertTrue($draft->isMoneyMode());
        $response->assertRedirect(route('add.payout-tiers.form', $draft->id));
    }

    public function test_items_mode_still_redirects_to_the_items_step(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('create.draft'), $this->payload(['giveaway_mode' => 'items']));

        $draft = Draft::where('name', 'Cash prize')->sole();
        $this->assertFalse($draft->isMoneyMode());
        $response->assertRedirect(route('add.interests.form', ['draft_id' => $draft->id, 'no_of_interests' => $draft->no_of_interests]));
    }

    public function test_giveaway_mode_defaults_to_items_when_omitted(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('create.draft'), $this->payload());

        $this->assertSame('items', Draft::where('name', 'Cash prize')->sole()->giveaway_mode);
    }

    public function test_giveaway_mode_is_ignored_for_bidding(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('create.draft'), $this->payload(['type' => 'bidding', 'giveaway_mode' => 'money']));

        $draft = Draft::where('name', 'Cash prize')->sole();
        $this->assertSame('bidding', $draft->type);
        $this->assertSame('items', $draft->giveaway_mode);
        $this->assertFalse($draft->isMoneyMode());
    }

    // ------------------------------------------------------------------ starting: readiness

    public function test_starting_requires_at_least_one_payout_tier(): void
    {
        [$draft] = $this->buildMoneyDraft(3, []);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))
            ->assertSessionHasErrors(['start' => 'This draft has no payout tiers set up.']);
        $this->assertNull($draft->fresh()->turn_started_at);
    }

    public function test_starting_requires_every_invited_participant_to_have_joined(): void
    {
        [$draft] = $this->buildMoneyDraft(2);
        Team::factory()->invited()->create(['draft_id' => $draft->id, 'email' => 'late@example.com']);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))
            ->assertSessionHasErrors(['start' => 'Every invited participant must join before results can be shown.']);
        $this->assertNull($draft->fresh()->turn_started_at);
    }

    public function test_host_decided_requires_the_host_to_set_the_rank_order_before_starting(): void
    {
        [$draft] = $this->buildMoneyDraft(3, null, ['order_mode' => 'host_decided']);
        Team::where('draft_id', $draft->id)->update(['selection_no' => null]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))
            ->assertSessionHasErrors(['start' => 'Every participant must have a selection order before this draft can start.']);
        $this->assertNull($draft->fresh()->turn_started_at);
    }

    public function test_fcfs_needs_no_host_action_before_starting(): void
    {
        [$draft] = $this->buildMoneyDraft(3, null, ['order_mode' => 'fcfs']);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))->assertSessionHasNoErrors();
        $this->assertNotNull($draft->fresh()->turn_started_at);
    }

    // ------------------------------------------------------------------ starting: the reveal

    public function test_random_shuffles_every_participant_exactly_once_at_start(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(5, null, ['order_mode' => 'random']);
        Team::where('draft_id', $draft->id)->update(['selection_no' => null]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))->assertSessionHasNoErrors();

        $assigned = Team::where('draft_id', $draft->id)->pluck('selection_no')->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4, 5], $assigned);
    }

    public function test_starting_twice_does_not_reshuffle_or_re_broadcast(): void
    {
        [$draft] = $this->buildMoneyDraft(3, null, ['order_mode' => 'random']);
        Team::where('draft_id', $draft->id)->update(['selection_no' => null]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));
        $first = Team::where('draft_id', $draft->id)->orderBy('id')->pluck('selection_no')->all();

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));
        $second = Team::where('draft_id', $draft->id)->orderBy('id')->pluck('selection_no')->all();

        $this->assertSame($first, $second);
    }

    public function test_the_reveal_pays_each_rank_from_its_tier(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(4, [
            ['rank_from' => 1, 'rank_to' => 1, 'amount' => 20000],
            ['rank_from' => 2, 'rank_to' => 3, 'amount' => 5000],
            // Rank 4 is covered by no tier at all: it is paid nothing, explicitly.
        ]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id))->assertSessionHasNoErrors();

        $state = $this->actingAs($players[0])->getJson(route('draft.state', $draft->id))->assertOk()->json();
        $this->assertSame('complete', $state['status']);

        $amountsByRank = collect($state['results'])->pluck('amount', 'rank');
        $this->assertSame(20000, $amountsByRank[1]);
        $this->assertSame(5000, $amountsByRank[2]);
        $this->assertSame(5000, $amountsByRank[3]);
        $this->assertSame(0, $amountsByRank[4]);
    }

    public function test_the_reveal_is_broadcast_live_to_everyone_on_the_results_page(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(3);
        Event::fake([DraftStateChanged::class]);

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));

        Event::assertDispatched(DraftStateChanged::class, function (DraftStateChanged $event) use ($draft) {
            $state = $event->broadcastWith();

            return $event->broadcastOn()->name === 'private-draft.'.$draft->id
                && $state['status'] === 'complete'
                && count($state['results']) === 3;
        });
    }

    public function test_before_start_the_results_page_shows_no_results_yet(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(3);

        $state = $this->actingAs($players[0])->getJson(route('draft.state', $draft->id))->assertOk()->json();

        $this->assertContains($state['status'], ['scheduled', 'waiting']);
        $this->assertSame([], $state['results']);
    }

    public function test_the_results_page_is_shown_for_a_money_mode_draft(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(2);
        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));

        $this->actingAs($players[0])->get(route('show.interests', $draft->id))
            ->assertOk()
            ->assertViewIs('payout_result');
    }

    public function test_advance_clock_never_does_anything_in_money_mode(): void
    {
        [$draft] = $this->buildMoneyDraft(3);
        $service = app(DraftPayoutService::class);

        $this->assertNull($service->advanceClock($draft));

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));
        $this->assertNull($service->advanceClock($draft->fresh()));
    }

    // ------------------------------------------------------------------ locked once started

    public function test_a_money_draft_can_no_longer_be_edited_once_started(): void
    {
        [$draft, $players, $tiers] = $this->buildMoneyDraft(3);
        $draft->update(['turn_started_at' => now()]);
        $editor = app(DraftEditor::class);

        foreach ([
            fn () => $editor->addPayoutTiers($draft, [['rank_from' => 4, 'rank_to' => 4, 'amount' => 1]]),
            fn () => $editor->updatePayoutTier($draft, $tiers[0]->id, 1, 1, 1),
            fn () => $editor->removePayoutTier($draft, $tiers[0]->id),
            fn () => $editor->addParticipants($draft, ['late@example.com']),
        ] as $edit) {
            try {
                $edit();
                $this->fail('expected a 403 once the draft has started');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    // ------------------------------------------------------------------ display

    public function test_the_details_page_shows_payout_tiers_instead_of_items(): void
    {
        [$draft] = $this->buildMoneyDraft(2, [
            ['rank_from' => 1, 'rank_to' => 1, 'amount' => 1000],
            ['rank_from' => 2, 'rank_to' => 2, 'amount' => 500],
        ]);

        $this->actingAs($draft->creator)->get(route('draft.details', $draft->id))
            ->assertOk()
            ->assertSee('Payout tiers')
            ->assertSee('<strong>Payout tiers:</strong> 2', false)
            ->assertDontSee('<strong>Items:</strong>', false);
    }

    public function test_the_edit_page_shows_a_payout_tiers_section_instead_of_items(): void
    {
        [$draft, , $tiers] = $this->buildMoneyDraft(2, [['rank_from' => 1, 'rank_to' => 2, 'amount' => 750]]);

        $this->actingAs($draft->creator)->get(route('draft.edit', $draft->id))
            ->assertOk()
            ->assertSee('Payout Tiers')
            ->assertSee('750')
            ->assertDontSee('Add an item');
    }

    public function test_the_dashboard_reports_a_started_money_draft_as_complete(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(2);

        $this->actingAs($draft->creator)->get(route('dashboard'))->assertOk()->assertSee('Not started');

        $this->actingAs($draft->creator)->post(route('start.draft', $draft->id));

        $this->actingAs($draft->creator)->get(route('dashboard'))->assertOk()->assertSee('Complete');
    }
}
