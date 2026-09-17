<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeamController extends Controller
{

    public function showInviteForm($draft_id, $no_of_teams)
    {
        // Pass the draft ID and number of interests to the view
        return view('send_invitations', compact('draft_id', 'no_of_teams'));
    }

    public function inviteTeams(Request $request, $draft_id)
    {
        $validated = $request->validate([
            'emails' => ['required', 'array', 'min:1'],
            'emails.*' => ['required', 'email', 'distinct'],
        ]);

        $draft = Draft::findOrFail($draft_id);

        // An invitation may be sent before the participant has an account.
        foreach ($validated['emails'] as $email) {
            $token = Str::random(32);

            Team::create([
                'user_id' => null,
                'email' => $email,
                'draft_id' => $draft->id,
                'selection_no' => null,
                'token' => $token,
            ]);
        }

        return redirect()->route('invitations.sent', ['draft_id' => $draft_id]);
    }

    public function showInvitationsSent($draft_id)
    {
        $draft = Draft::findOrFail($draft_id);
        $teams = $draft->teams()->orderBy('id')->get();

        return view('invitations_sent', compact('draft', 'teams'));
    }

    public function showSelectionForm($draft_id)
    {
        // Fetch all teams for the given draft
        $teams = Team::where('draft_id', $draft_id)->with('user')->get();

        // Pass the teams and the draft ID to the view
        return view('selection_order', compact('teams', 'draft_id'));
    }

    public function storeSelectionOrder(Request $request, $draft_id)
    {
        // Validate the selection numbers
        $request->validate([
            'selection_numbers' => 'required|array|min:1',
            'selection_numbers.*' => 'required|integer|min:1',
        ]);

        // Extract all the selection numbers
        $selectionNumbers = $request->input('selection_numbers');

        // Ensure no duplicate selection numbers
        if (count($selectionNumbers) !== count(array_unique($selectionNumbers))) {
            return back()->withErrors(['Selection numbers must be unique.']);
        }

        // Loop through the selection numbers and update each team
        foreach ($selectionNumbers as $team_id => $selection_no) {
            $team = Team::findOrFail($team_id);

            // Update the team with the assigned selection number
            $team->update([
                'selection_no' => $selection_no,
            ]);
        }
        return redirect()->route('draft.created', ['draft_id' => $draft_id]);
    }

    public function showDraftCreated($draft_id)
    {
        $draft = Draft::findOrFail($draft_id);

        return view('draft_created', compact('draft'));
    }
}
