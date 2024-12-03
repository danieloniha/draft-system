<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Models\Team;
use App\Models\User;
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
        // Validate email input
        $request->validate([
            'username' => 'required|array|min:1',
            'username.*' => 'required|string'
        ]);

        $draft = Draft::findOrFail($draft_id);

        // For each email, create a team and generate a token for that team
        foreach ($request->username as $username) {
            $user = User::where('username', $username)->first();
            $token = Str::random(32); // Generate a random token for the team

            $team = Team::create([
                'user_id' => $user->id, // Will be updated when user joins with the token
                'draft_id' => $draft->id,
                'selection_no' => null, // Can be updated later when team order is assigned
                'token' => $token,
            ]);

            // Send the email with the invitation link
            //Mail::to($email)->send(new TeamInviteMail($team, $draft));
        }

        return redirect()->route('show.selection.order', ['draft_id' => $draft_id]);
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

        //return redirect()->route('some.next.route')->with('success', 'Selection order saved successfully!');
    }
}
