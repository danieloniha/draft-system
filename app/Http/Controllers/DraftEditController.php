<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Services\DraftEditor;
use Illuminate\Http\Request;

/**
 * The host's page for changing a draft before it starts. Adding items, inviting
 * people and saving the order reuse the endpoints the creation wizard uses
 * (with return_to=edit); this controller holds what the wizard never needed:
 * changing settings, and changing or removing what is already there.
 *
 * Every action needs the draft's "configure" permission: the host, and only
 * until the draft has started.
 */
class DraftEditController extends Controller
{
    public function edit($draft_id)
    {
        $draft = Draft::with(['interests', 'teams.user'])->findOrFail($draft_id);
        $this->authorize('configure', $draft);

        // In order first, anyone not yet placed last.
        $teams = $draft->teams
            ->sortBy(fn ($team) => [$team->selection_no ?? PHP_INT_MAX, $team->id])
            ->values();

        // Offer each unplaced participant the lowest number nobody has yet.
        $free = collect(range(1, max($teams->count(), 1)))->diff($teams->pluck('selection_no'))->values();
        $suggested = $teams->whereNull('selection_no')->values()
            ->mapWithKeys(fn ($team, $position) => [$team->id => $free[$position] ?? null]);

        return view('draft_edit', compact('draft', 'teams', 'suggested'));
    }

    public function update(Request $request, $draft_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'timer' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $editor->updateSettings($draft, [
            'name' => $validated['name'],
            'title' => $validated['title'],
            'selection_time_limit' => $validated['timer'],
            'start_date' => Draft::parseStartDate($validated['start_date'], $validated['timezone'] ?? null),
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

    public function removeParticipant($draft_id, $team_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $editor->removeParticipant($draft, (int) $team_id);

        return $this->backToEdit($draft, 'Participant removed.');
    }

    private function backToEdit(Draft $draft, string $message)
    {
        return redirect()->route('draft.edit', ['draft_id' => $draft->id])->with('status', $message);
    }
}
