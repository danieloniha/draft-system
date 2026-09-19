<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Models\Team;
use App\Services\DraftEditor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{

    public function showInviteForm($draft_id, $no_of_teams)
    {
        $this->authorize('configure', Draft::findOrFail($draft_id));

        // Pass the draft ID and number of interests to the view
        return view('send_invitations', compact('draft_id', 'no_of_teams'));
    }

    public function inviteTeams(Request $request, $draft_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        // One seat per email: a duplicate would let one person join twice and hold two turns.
        $validated = $request->validate([
            'emails' => ['required', 'array', 'min:1'],
            'emails.*' => [
                'required',
                'email',
                'distinct:ignore_case',
                // joinDraft() matches emails case-insensitively, so this must too.
                function (string $attribute, mixed $value, Closure $fail) use ($draft) {
                    if ($draft->teams()->whereRaw('lower(email) = ?', [Str::lower($value)])->exists()) {
                        $fail('This email has already been invited to this draft.');
                    }
                },
            ],
            'return_to' => ['nullable', Rule::in(['edit'])],
        ]);

        $editor->addParticipants($draft, $validated['emails']);

        // The edit page invites people one at a time and comes straight back to itself.
        if (($validated['return_to'] ?? null) === 'edit') {
            return redirect()->route('draft.edit', ['draft_id' => $draft->id])
                ->with('status', 'Invitation created. Their link is on the invitation links page.');
        }

        return redirect()->route('invitations.sent', ['draft_id' => $draft_id]);
    }

    public function showInvitationsSent($draft_id)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('manage', $draft);

        $teams = $draft->teams()->orderBy('id')->get();

        return view('invitations_sent', compact('draft', 'teams'));
    }

    public function showSelectionForm($draft_id)
    {
        $this->authorize('configure', Draft::findOrFail($draft_id));

        // Fetch all teams for the given draft
        $teams = Team::where('draft_id', $draft_id)->with('user')->get();

        // Pass the teams and the draft ID to the view
        return view('selection_order', compact('teams', 'draft_id'));
    }

    public function storeSelectionOrder(Request $request, $draft_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        // Validate the selection numbers
        $validated = $request->validate([
            'selection_numbers' => 'required|array|min:1',
            'selection_numbers.*' => 'required|integer|min:1',
            'return_to' => ['nullable', Rule::in(['edit'])],
        ]);

        // Unique numbers, and a place for every participant of this draft and nobody else
        $editor->setOrder($draft, $validated['selection_numbers']);

        if (($validated['return_to'] ?? null) === 'edit') {
            return redirect()->route('draft.edit', ['draft_id' => $draft->id])->with('status', 'Selection order saved.');
        }

        return redirect()->route('draft.created', ['draft_id' => $draft->id]);
    }

    public function showDraftCreated($draft_id)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('manage', $draft);

        return view('draft_created', compact('draft'));
    }
}
