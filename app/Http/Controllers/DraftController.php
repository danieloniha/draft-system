<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Models\Interest;
use App\Models\Selection;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DraftController extends Controller
{
    //
    public function draftPage()
    {
        return view('create_draft');
    }

    public function createDraft(Request $request)
    {
        //dd($request->all());

        $validated = $request->validate([
            'name' => 'required|string',
            'title' => 'required|string',
            'no_interests' => 'required|integer|min:1',
            'no_teams' => 'required|integer|min:1',
            'timer' => 'required|integer|min:1',
            'start_date' => 'required',
        ]);

        // Create draft
        $draft = Draft::create([
            'name' => $validated['name'],
            'title' => $validated['title'],
            'no_of_interests' => $validated['no_interests'],
            'no_of_teams' => $validated['no_teams'],
            'selection_time_limit' => $validated['timer'],
            'start_date' => $validated['start_date'],
        ]);

        // Redirect to a new form to add the interests with the draft id and number of interests
        return redirect()->route('add.interests.form', [
            'draft_id' => $draft->id,
            'no_of_interests' => $draft->no_of_interests,
        ]);
    }

    public function showInterestForm($draft_id, $no_of_interests)
    {
        // Pass the draft ID and number of interests to the view
        return view('add_interests', compact('draft_id', 'no_of_interests'));
    }

    public function storeInterests(Request $request, $draft_id)
    {
        // Validate the input
        $request->validate([
            'interests' => 'required|array|min:1',
            'interests.*' => 'required|string|max:255',
        ]);

        // Save each interest
        foreach ($request->interests as $interest) {
            Interest::create([
                'draft_id' => $draft_id,
                'interest' => $interest,
            ]);
        }
        $draft = Draft::findOrFail($draft_id);
        return redirect()->route('add.teams.form', [
            'draft_id' => $draft->id,
            'no_of_teams' => $draft->no_of_teams,
        ]);
    }

    public function showJoinDraftForm()
    {
        return view('join_draft');
    }

    public function joinDraft(Request $request)
    {

        $validated = $request->validate([
            'token' => 'required|string|exists:teams,token',
        ]);

        $userId = auth()->id();

        // Retrieve the team by token and ensure that it belongs to the logged-in user
        $team = Team::where('token', $validated['token'])
            ->where('user_id', $userId)  // Ensure the token belongs to the logged-in user
            ->first();

        if (!$team) {
            // If the team doesn't belong to the user, abort with a 403 Forbidden response or redirect with error
            return redirect()->back()->withErrors(['token' => 'This token is invalid or does not belong to your account.']);
        }

        return redirect()->route('draft.details', ['draft_id' => $team->draft_id]);
    }

    public function showDraftDetails($draft_id)
    {

        $draft = Draft::with('teams.user')->findOrFail($draft_id);

        $teams = $draft->teams()->orderBy('selection_no')->get();

        return view('draft_details', compact('draft', 'teams'));
    }

    public function startDraft($draft_id)
    {

        $draft = Draft::findOrFail($draft_id);

        return redirect()->route('show.interests', ['draft_id' => $draft_id]);
    }

    public function showInterests($draft_id)
    {

        $draft = Draft::with('teams.user')->findOrFail($draft_id);
        $interests = Interest::where('draft_id', $draft_id)->get();

        // Get the list of players with their selection numbers
        $players = $draft->teams->map(function ($team) {
            return [
                'id' => $team->user->id,
                'username' => $team->user->username,
                'selection_no' => $team->selection_no
            ];
        })->sortBy('selection_no')->values();
        $totalPlayers = $draft->teams->count();

        return view('selection', compact('draft', 'interests', 'players', 'totalPlayers'));
    }

    public function selectInterest(Request $request, $draft_id)
    {

        $validated = $request->validate([
            'interest_id' => 'required|integer|exists:interests,id',
            'player_id' => 'required|integer|exists:users,id',
        ]);

        // Find the team_id that corresponds to the player_id in this draft
        $team = Team::where('draft_id', $draft_id)
            ->where('user_id', $validated['player_id'])  // Match player_id (user_id) in teams
            ->first();

        if (!$team) {
            return response()->json(['success' => false, 'message' => 'No team found for the current player in this draft']);
        }

        $existingSelection = Selection::where('interest_id', $validated['interest_id'])
            ->where('draft_id', $draft_id)  // Ensure it's specific to the draft
            ->first();
        if ($existingSelection) {
            return response()->json(['success' => false, 'message' => 'Interest already selected']);
        }

        $interest = Interest::find($validated['interest_id']);
        $selection = new Selection();
        $selection->interest_id = $validated['interest_id'];
        $selection->team_id = $team->id;
        $selection->draft_id = $draft_id;
        $selection->selected = $interest->name;
        $selection->is_selected = true;
        $selection->save();

        return response()->json(['success' => true, 'message' => 'Interest selected successfully']);
    }
}
