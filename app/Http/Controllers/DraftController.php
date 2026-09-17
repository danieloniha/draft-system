<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Models\Interest;
use App\Models\Selection;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'string', 'max:255'],
            'item_images' => ['nullable', 'array'],
            'item_images.*' => ['nullable', 'image', 'max:5120'],
        ]);

        foreach ($validated['items'] as $index => $item) {
            $imagePath = $request->hasFile("item_images.$index")
                ? $request->file("item_images.$index")->store('items', 'public')
                : null;

            Interest::create([
                'draft_id' => $draft_id,
                'name' => $item,
                'image_path' => $imagePath,
            ]);
        }
        $draft = Draft::findOrFail($draft_id);
        return redirect()->route('add.teams.form', [
            'draft_id' => $draft->id,
            'no_of_teams' => $draft->no_of_teams,
        ]);
    }

    public function showJoinDraftForm(Request $request)
    {
        return view('join_draft', ['token' => $request->query('token')]);
    }

    public function joinDraft(Request $request)
    {

        $validated = $request->validate([
            'token' => 'required|string|exists:teams,token',
        ]);

        $userId = auth()->id();

        $team = Team::where('token', $validated['token'])->first();

        if (!$team || strcasecmp($team->email, auth()->user()->email) !== 0) {
            return redirect()->back()->withErrors(['token' => 'This invitation belongs to a different email address.']);
        }

        $team->update(['user_id' => $userId]);

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
        abort_unless($draft->teams->contains('user_id', auth()->id()), 403);
        abort_if(
            $draft->teams->contains(fn (Team $team) => is_null($team->user_id)),
            422,
            'Every invited participant must join before picking starts.'
        );

        $interests = $draft->interests;

        // Get the list of players with their selection numbers
        $players = $draft->teams->whereNotNull('selection_no')->map(function ($team) {
            return [
                'id' => $team->user->id,
                'username' => $team->user->username,
                'selection_no' => $team->selection_no
            ];
        })->sortBy('selection_no')->values();
        $totalPlayers = $draft->teams->count();
        abort_if($players->count() !== $totalPlayers, 422, 'Every participant must have a selection order before picking starts.');

        $selectionCount = $draft->selections()->count();
        $currentPlayerId = $players->isNotEmpty()
            ? $players[$selectionCount % $players->count()]['id']
            : null;
        $selectedInterestIds = $draft->selections()->pluck('interest_id')->all();

        return view('selection', compact(
            'draft',
            'interests',
            'players',
            'totalPlayers',
            'currentPlayerId',
            'selectedInterestIds'
        ));
    }

    public function selectInterest(Request $request, $draft_id)
    {
        $validated = $request->validate([
            'interest_id' => 'required|integer',
        ]);

        return DB::transaction(function () use ($draft_id, $validated) {
            // The draft lock serializes picks, so turn and availability checks use
            // one consistent state even when requests arrive concurrently.
            $draft = Draft::whereKey($draft_id)->lockForUpdate()->firstOrFail();

            $team = Team::where('draft_id', $draft->id)
                ->where('user_id', auth()->id())
                ->first();
            abort_unless($team, 403, 'You are not a participant in this draft.');

            $teams = Team::where('draft_id', $draft->id)
                ->whereNotNull('selection_no')
                ->orderBy('selection_no')
                ->lockForUpdate()
                ->get();
            abort_if($teams->isEmpty(), 422, 'The selection order has not been configured.');

            $selectionCount = Selection::where('draft_id', $draft->id)->count();
            $currentTeam = $teams[$selectionCount % $teams->count()];
            abort_unless($team->is($currentTeam), 403, 'It is not your turn to make a selection.');

            $interest = Interest::whereKey($validated['interest_id'])
                ->where('draft_id', $draft->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if(
                Selection::where('draft_id', $draft->id)
                    ->where('interest_id', $interest->id)
                    ->exists(),
                409,
                'This item has already been selected.'
            );

            Selection::create([
                'interest_id' => $interest->id,
                'team_id' => $team->id,
                'draft_id' => $draft->id,
                'selected' => $interest->name,
                'is_selected' => true,
            ]);

            $nextTeam = $teams[($selectionCount + 1) % $teams->count()];

            return response()->json([
                'success' => true,
                'message' => 'Interest selected successfully.',
                'next_player' => [
                    'id' => $nextTeam->user_id,
                    'selection_no' => $nextTeam->selection_no,
                ],
            ]);
        });
    }
}
