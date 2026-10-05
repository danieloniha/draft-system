<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Services\DraftEditor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The host's page for changing a draft before it starts. Adding items, inviting
 * people and saving the order reuse the endpoints the creation wizard uses
 * (with return_to=edit); this controller holds what the wizard never needed:
 * changing settings (including the template, visibility, order mode and
 * participant limit), changing or removing what is already there, and the
 * Bidding item-order editor (Giveaway's participant order already has one,
 * reused from the wizard: TeamController::storeSelectionOrder).
 *
 * Every action needs the draft's "configure" permission: the host, and only
 * until the draft has started.
 */
class DraftEditController extends Controller
{
    public function edit($draft_id)
    {
        $draft = Draft::with(['interests', 'teams.user', 'payoutTiers'])->findOrFail($draft_id);
        $this->authorize('configure', $draft);

        // In order first, anyone not yet placed last.
        $teams = $draft->teams
            ->sortBy(fn ($team) => [$team->selection_no ?? PHP_INT_MAX, $team->id])
            ->values();

        // Offer each unplaced participant the lowest number nobody has yet.
        $free = collect(range(1, max($teams->count(), 1)))->diff($teams->pluck('selection_no'))->values();
        $suggested = $teams->whereNull('selection_no')->values()
            ->mapWithKeys(fn ($team, $position) => [$team->id => $free[$position] ?? null]);

        // Same idea, for Bidding's item order. Items always get a position the
        // moment they're added (see DraftEditor::addItems), so in practice every
        // item already has one here — this only matters for older data.
        $items = $draft->interests
            ->sortBy(fn ($item) => [$item->position ?? PHP_INT_MAX, $item->id])
            ->values();
        $freeItemPositions = collect(range(1, max($items->count(), 1)))->diff($items->pluck('position'))->values();
        $suggestedItemPositions = $items->whereNull('position')->values()
            ->mapWithKeys(fn ($item, $position) => [$item->id => $freeItemPositions[$position] ?? null]);

        return view('draft_edit', compact('draft', 'teams', 'suggested', 'items', 'suggestedItemPositions'));
    }

    public function update(Request $request, $draft_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'timer' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'type' => ['required', Rule::in(['giveaway', 'bidding'])],
            'visibility' => ['required', Rule::in(['private', 'public'])],
            'order_mode' => ['required', Rule::in(['host_decided', 'fcfs', 'random'])],
            'participant_limit' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $editor->updateSettings($draft, [
            'name' => $validated['name'],
            // Optional: an empty string (a blank but submitted field) means the same as leaving it out.
            'title' => ($validated['title'] ?? null) ?: null,
            'selection_time_limit' => $validated['timer'],
            'start_date' => Draft::parseStartDate($validated['start_date'], $validated['timezone'] ?? null),
            'type' => $validated['type'],
            'visibility' => $validated['visibility'],
            'order_mode' => $validated['order_mode'],
            'participant_limit' => $validated['participant_limit'],
        ]);

        return $this->backToEdit($draft, 'Settings saved.');
    }

    public function updateItem(Request $request, $draft_id, $interest_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        $editor->updateItem(
            $draft,
            (int) $interest_id,
            $validated['name'],
            $request->file('image'),
            $request->boolean('remove_image')
        );

        return $this->backToEdit($draft, 'Item saved.');
    }

    public function removeItem($draft_id, $interest_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $editor->removeItem($draft, (int) $interest_id);

        return $this->backToEdit($draft, 'Item removed.');
    }

    public function updatePayoutTier(Request $request, $draft_id, $tier_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $validated = $request->validate([
            'rank_from' => ['required', 'integer', 'min:1'],
            'rank_to' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $editor->updatePayoutTier(
            $draft,
            (int) $tier_id,
            (int) $validated['rank_from'],
            (int) $validated['rank_to'],
            (int) $validated['amount']
        );

        return $this->backToEdit($draft, 'Payout tier saved.');
    }

    public function removePayoutTier($draft_id, $tier_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $editor->removePayoutTier($draft, (int) $tier_id);

        return $this->backToEdit($draft, 'Payout tier removed.');
    }

    public function updatePayoutBudget(Request $request, $draft_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $validated = $request->validate([
            'payout_budget' => ['required', 'integer', 'min:1'],
        ]);

        $editor->updatePayoutBudget($draft, (int) $validated['payout_budget']);

        return $this->backToEdit($draft, 'Budget saved.');
    }

    public function removeParticipant($draft_id, $team_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $editor->removeParticipant($draft, (int) $team_id);

        return $this->backToEdit($draft, 'Participant removed.');
    }

    public function updateItemOrder(Request $request, $draft_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $validated = $request->validate([
            'positions' => 'required|array|min:1',
            'positions.*' => 'required|integer|min:1',
        ]);

        $editor->setItemOrder($draft, $validated['positions']);

        return $this->backToEdit($draft, 'Item order saved.');
    }

    private function backToEdit(Draft $draft, string $message)
    {
        return redirect()->route('draft.edit', ['draft_id' => $draft->id])->with('status', $message);
    }
}
