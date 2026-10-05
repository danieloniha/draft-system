<?php

namespace Tests\Feature;

use App\Models\PayoutTier;
use App\Models\User;
use App\Services\DraftEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsDrafts;
use Tests\TestCase;

/**
 * The host's payout table for a Giveaway "money" draft: adding, editing and
 * removing tiers, both through DraftEditor directly and through the wizard
 * and edit-page HTTP endpoints, and the same edit-lock every other part of a
 * draft already has once it has started.
 */
class PayoutTierManagementTest extends TestCase
{
    use BuildsDrafts;
    use RefreshDatabase;

    // ------------------------------------------------------------------ DraftEditor

    public function test_the_host_can_add_payout_tiers(): void
    {
        [$draft] = $this->buildMoneyDraft(3, []);
        $editor = app(DraftEditor::class);

        $editor->addPayoutTiers($draft, [
            ['rank_from' => 1, 'rank_to' => 1, 'amount' => 20000],
            ['rank_from' => 2, 'rank_to' => 3, 'amount' => 5000],
        ]);

        $tiers = PayoutTier::where('draft_id', $draft->id)->orderBy('rank_from')->get();
        $this->assertSame(2, $tiers->count());
        $this->assertSame([1, 1, 20000], [$tiers[0]->rank_from, $tiers[0]->rank_to, $tiers[0]->amount]);
        $this->assertSame([2, 3, 5000], [$tiers[1]->rank_from, $tiers[1]->rank_to, $tiers[1]->amount]);
    }

    public function test_a_tier_range_must_be_valid(): void
    {
        [$draft] = $this->buildMoneyDraft(3, []);
        $editor = app(DraftEditor::class);

        foreach ([
            ['rank_from' => 0, 'rank_to' => 1, 'amount' => 100],
            ['rank_from' => 3, 'rank_to' => 2, 'amount' => 100],
            ['rank_from' => 1, 'rank_to' => 1, 'amount' => 0],
        ] as $bad) {
            try {
                $editor->addPayoutTiers($draft, [$bad]);
                $this->fail('expected a validation exception for '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        $this->assertSame(0, PayoutTier::where('draft_id', $draft->id)->count());
    }

    public function test_tiers_may_not_overlap(): void
    {
        [$draft] = $this->buildMoneyDraft(5, [['rank_from' => 1, 'rank_to' => 3, 'amount' => 1000]]);
        $editor = app(DraftEditor::class);

        try {
            $editor->addPayoutTiers($draft, [['rank_from' => 3, 'rank_to' => 5, 'amount' => 500]]);
            $this->fail('expected a validation exception for an overlapping tier');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rank_to', $e->errors());
        }

        $this->assertSame(1, PayoutTier::where('draft_id', $draft->id)->count());
    }

    public function test_tiers_within_the_same_batch_may_not_overlap_each_other(): void
    {
        [$draft] = $this->buildMoneyDraft(5, []);
        $editor = app(DraftEditor::class);

        try {
            $editor->addPayoutTiers($draft, [
                ['rank_from' => 1, 'rank_to' => 3, 'amount' => 1000],
                ['rank_from' => 2, 'rank_to' => 4, 'amount' => 500],
            ]);
            $this->fail('expected a validation exception for an overlapping tier');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rank_to', $e->errors());
        }
    }

    public function test_the_host_can_update_a_tier(): void
    {
        [$draft, , $tiers] = $this->buildMoneyDraft(5, [['rank_from' => 1, 'rank_to' => 3, 'amount' => 1000]]);
        $editor = app(DraftEditor::class);

        $editor->updatePayoutTier($draft, $tiers[0]->id, 1, 2, 2000);

        $tier = $tiers[0]->fresh();
        $this->assertSame(1, $tier->rank_from);
        $this->assertSame(2, $tier->rank_to);
        $this->assertSame(2000, $tier->amount);
    }

    public function test_updating_a_tier_is_checked_against_the_other_tiers_not_itself(): void
    {
        [$draft, , $tiers] = $this->buildMoneyDraft(5, [
            ['rank_from' => 1, 'rank_to' => 2, 'amount' => 1000],
            ['rank_from' => 3, 'rank_to' => 5, 'amount' => 500],
        ]);
        $editor = app(DraftEditor::class);

        // Widening the first tier to keep its own range plus one more rank is fine...
        $editor->updatePayoutTier($draft, $tiers[0]->id, 1, 2, 1000);

        // ...but growing into the second tier's range is not.
        try {
            $editor->updatePayoutTier($draft, $tiers[0]->id, 1, 3, 1000);
            $this->fail('expected a validation exception for an overlapping tier');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rank_to', $e->errors());
        }
    }

    public function test_the_host_can_remove_a_tier(): void
    {
        [$draft, , $tiers] = $this->buildMoneyDraft(5, [
            ['rank_from' => 1, 'rank_to' => 2, 'amount' => 1000],
            ['rank_from' => 3, 'rank_to' => 5, 'amount' => 500],
        ]);
        $editor = app(DraftEditor::class);

        $editor->removePayoutTier($draft, $tiers[0]->id);

        $this->assertSame(1, PayoutTier::where('draft_id', $draft->id)->count());
        $this->assertNull(PayoutTier::find($tiers[0]->id));
    }

    public function test_a_tier_of_another_draft_cannot_be_changed_or_removed(): void
    {
        [$draft] = $this->buildMoneyDraft(3, []);
        [, , $foreignTiers] = $this->buildMoneyDraft(3, [['rank_from' => 1, 'rank_to' => 1, 'amount' => 100]]);

        $this->actingAs($draft->creator)
            ->patch(route('draft.payout-tiers.update', [$draft->id, $foreignTiers[0]->id]), ['rank_from' => 1, 'rank_to' => 1, 'amount' => 200])
            ->assertNotFound();
        $this->actingAs($draft->creator)
            ->delete(route('draft.payout-tiers.destroy', [$draft->id, $foreignTiers[0]->id]))
            ->assertNotFound();

        $this->assertSame(100, $foreignTiers[0]->fresh()->amount);
    }

    // ------------------------------------------------------------------ budget

    public function test_a_draft_with_no_budget_set_is_not_checked(): void
    {
        // Defensive only: money mode always sets a budget at creation (via the wizard), so this
        // only matters for a draft built another way.
        [$draft] = $this->buildMoneyDraft(3, [], ['payout_budget' => null]);
        $editor = app(DraftEditor::class);

        $editor->addPayoutTiers($draft, [['rank_from' => 1, 'rank_to' => 3, 'amount' => 1000000]]);

        $this->assertSame(1, PayoutTier::where('draft_id', $draft->id)->count());
    }

    public function test_tiers_may_not_add_up_to_more_than_the_budget(): void
    {
        [$draft] = $this->buildMoneyDraft(5, [], ['payout_budget' => 10000]);
        $editor = app(DraftEditor::class);

        try {
            // 5 ranks * 3000 each = 15000, over the 10000 budget.
            $editor->addPayoutTiers($draft, [['rank_from' => 1, 'rank_to' => 5, 'amount' => 3000]]);
            $this->fail('expected a validation exception for exceeding the budget');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payout_budget', $e->errors());
        }

        $this->assertSame(0, PayoutTier::where('draft_id', $draft->id)->count());
    }

    public function test_tiers_together_may_not_add_up_to_more_than_the_budget(): void
    {
        [$draft] = $this->buildMoneyDraft(5, [
            ['rank_from' => 1, 'rank_to' => 2, 'amount' => 4000],
        ], ['payout_budget' => 10000]);
        $editor = app(DraftEditor::class);

        // The existing tier already allocates 8000. This new tier alone (2 ranks * 2000 = 4000)
        // is fine on its own, but together they total 12000 — over budget.
        try {
            $editor->addPayoutTiers($draft, [['rank_from' => 3, 'rank_to' => 4, 'amount' => 2000]]);
            $this->fail('expected a validation exception for exceeding the budget');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payout_budget', $e->errors());
        }

        $this->assertSame(1, PayoutTier::where('draft_id', $draft->id)->count());
    }

    public function test_updating_a_tier_may_not_push_the_total_over_budget(): void
    {
        [$draft, , $tiers] = $this->buildMoneyDraft(5, [
            ['rank_from' => 1, 'rank_to' => 1, 'amount' => 5000],
            ['rank_from' => 2, 'rank_to' => 2, 'amount' => 3000],
        ], ['payout_budget' => 10000]);
        $editor = app(DraftEditor::class);

        try {
            $editor->updatePayoutTier($draft, $tiers[1]->id, 2, 2, 6000);
            $this->fail('expected a validation exception for exceeding the budget');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payout_budget', $e->errors());
        }

        $this->assertSame(3000, $tiers[1]->fresh()->amount);
    }

    public function test_the_host_can_change_the_budget(): void
    {
        [$draft] = $this->buildMoneyDraft(3, [], ['payout_budget' => 10000]);
        $editor = app(DraftEditor::class);

        $editor->updatePayoutBudget($draft, 20000);

        $this->assertSame(20000, $draft->fresh()->payout_budget);
    }

    public function test_the_budget_cannot_be_set_below_what_is_already_allocated(): void
    {
        [$draft] = $this->buildMoneyDraft(5, [
            ['rank_from' => 1, 'rank_to' => 5, 'amount' => 2000],
        ], ['payout_budget' => 10000]);
        $editor = app(DraftEditor::class);

        try {
            $editor->updatePayoutBudget($draft, 5000);
            $this->fail('expected a validation exception for a budget below the allocated total');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payout_budget', $e->errors());
        }

        $this->assertSame(10000, $draft->fresh()->payout_budget);
    }

    public function test_the_wizard_step_requires_a_budget(): void
    {
        [$draft] = $this->buildMoneyDraft(0, []);

        $this->actingAs($draft->creator)
            ->post(route('store.payout-tiers', $draft->id), ['rank_from' => [1], 'rank_to' => [1], 'amount' => [100]])
            ->assertSessionHasErrors('payout_budget');

        $this->assertSame(0, PayoutTier::where('draft_id', $draft->id)->count());
    }

    public function test_adding_a_tier_from_the_edit_page_does_not_need_the_budget_resent(): void
    {
        [$draft] = $this->buildMoneyDraft(3, [], ['payout_budget' => 10000]);

        $this->actingAs($draft->creator)
            ->post(route('store.payout-tiers', $draft->id), [
                'rank_from' => [1], 'rank_to' => [1], 'amount' => [500], 'return_to' => 'edit',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(10000, $draft->fresh()->payout_budget);
    }

    public function test_the_host_can_update_the_budget_from_the_edit_page(): void
    {
        [$draft] = $this->buildMoneyDraft(3, [], ['payout_budget' => 10000]);

        $this->actingAs($draft->creator)
            ->patch(route('draft.payout-budget.update', $draft->id), ['payout_budget' => 20000])
            ->assertRedirect(route('draft.edit', $draft->id))
            ->assertSessionHas('status', 'Budget saved.');

        $this->assertSame(20000, $draft->fresh()->payout_budget);
    }

    public function test_only_the_host_can_update_the_budget(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(3, [], ['payout_budget' => 10000]);

        $this->actingAs($players[0])
            ->patch(route('draft.payout-budget.update', $draft->id), ['payout_budget' => 20000])
            ->assertForbidden();

        $this->assertSame(10000, $draft->fresh()->payout_budget);
    }

    public function test_the_budget_is_locked_once_the_draft_has_started(): void
    {
        [$draft] = $this->buildMoneyDraft(3, [], ['payout_budget' => 10000]);
        $draft->update(['turn_started_at' => now()]);

        $this->actingAs($draft->creator)
            ->patch(route('draft.payout-budget.update', $draft->id), ['payout_budget' => 20000])
            ->assertForbidden();

        $this->assertSame(10000, $draft->fresh()->payout_budget);
    }

    // ------------------------------------------------------------------ HTTP: the wizard step

    public function test_the_wizard_step_stores_tiers_and_continues_to_inviting_people(): void
    {
        [$draft] = $this->buildMoneyDraft(0, []);

        $this->actingAs($draft->creator)
            ->post(route('store.payout-tiers', $draft->id), [
                'payout_budget' => 30000,
                'rank_from' => [1, 2],
                'rank_to' => [1, 3],
                'amount' => [20000, 5000],
            ])
            ->assertRedirect(route('add.teams.form', ['draft_id' => $draft->id, 'no_of_teams' => $draft->no_of_teams]));

        $this->assertSame(2, PayoutTier::where('draft_id', $draft->id)->count());
        $this->assertSame(30000, $draft->fresh()->payout_budget);
    }

    public function test_the_edit_page_stores_a_single_tier_and_returns_to_itself(): void
    {
        [$draft] = $this->buildMoneyDraft(3, []);

        $this->actingAs($draft->creator)
            ->post(route('store.payout-tiers', $draft->id), [
                'rank_from' => [1],
                'rank_to' => [3],
                'amount' => [1000],
                'return_to' => 'edit',
            ])
            ->assertRedirect(route('draft.edit', $draft->id))
            ->assertSessionHas('status', 'Payout tiers added.');

        $this->assertSame(1, PayoutTier::where('draft_id', $draft->id)->count());
    }

    public function test_only_the_host_can_store_payout_tiers(): void
    {
        [$draft, $players] = $this->buildMoneyDraft(3, []);

        $this->actingAs($players[0])
            ->post(route('store.payout-tiers', $draft->id), ['rank_from' => [1], 'rank_to' => [1], 'amount' => [100]])
            ->assertForbidden();

        $this->assertSame(0, PayoutTier::where('draft_id', $draft->id)->count());
    }

    // ------------------------------------------------------------------ HTTP: the edit page

    public function test_the_host_can_update_a_tier_from_the_edit_page(): void
    {
        [$draft, , $tiers] = $this->buildMoneyDraft(3, [['rank_from' => 1, 'rank_to' => 3, 'amount' => 1000]]);

        $this->actingAs($draft->creator)
            ->patch(route('draft.payout-tiers.update', [$draft->id, $tiers[0]->id]), [
                'rank_from' => 1, 'rank_to' => 2, 'amount' => 2000,
            ])
            ->assertRedirect(route('draft.edit', $draft->id))
            ->assertSessionHas('status', 'Payout tier saved.');

        $tier = $tiers[0]->fresh();
        $this->assertSame(2, $tier->rank_to);
        $this->assertSame(2000, $tier->amount);
    }

    public function test_the_host_can_remove_a_tier_from_the_edit_page(): void
    {
        [$draft, , $tiers] = $this->buildMoneyDraft(3, [['rank_from' => 1, 'rank_to' => 3, 'amount' => 1000]]);

        $this->actingAs($draft->creator)
            ->delete(route('draft.payout-tiers.destroy', [$draft->id, $tiers[0]->id]))
            ->assertRedirect(route('draft.edit', $draft->id))
            ->assertSessionHas('status', 'Payout tier removed.');

        $this->assertNull(PayoutTier::find($tiers[0]->id));
    }

    public function test_payout_tiers_are_locked_once_the_draft_has_started(): void
    {
        [$draft, , $tiers] = $this->buildMoneyDraft(3, [['rank_from' => 1, 'rank_to' => 3, 'amount' => 1000]]);
        $draft->update(['turn_started_at' => now()]);

        $this->actingAs($draft->creator)
            ->post(route('store.payout-tiers', $draft->id), ['rank_from' => [4], 'rank_to' => [4], 'amount' => [1]])
            ->assertForbidden();
        $this->actingAs($draft->creator)
            ->patch(route('draft.payout-tiers.update', [$draft->id, $tiers[0]->id]), ['rank_from' => 1, 'rank_to' => 1, 'amount' => 1])
            ->assertForbidden();
        $this->actingAs($draft->creator)
            ->delete(route('draft.payout-tiers.destroy', [$draft->id, $tiers[0]->id]))
            ->assertForbidden();

        $this->assertSame(1, PayoutTier::where('draft_id', $draft->id)->count());
        $this->assertSame(1000, $tiers[0]->fresh()->amount);
    }
}
