<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Models\Team;
use App\Services\DraftEditor;
use App\Services\DraftPickService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            'start_date' => 'required|date',
            'timezone' => 'nullable|timezone',
        ]);

        // The form sends a bare local time. Read it in the creator's timezone and
        // store it in the app's, or the draft would start at the wrong moment.
        $startsAt = Draft::parseStartDate($validated['start_date'], $validated['timezone'] ?? null);

        // Create draft
        $draft = Draft::create([
            'user_id' => auth()->id(),
            'name' => $validated['name'],
            'title' => $validated['title'],
            'no_of_interests' => $validated['no_interests'],
            'no_of_teams' => $validated['no_teams'],
            'selection_time_limit' => $validated['timer'],
            'start_date' => $startsAt,
        ]);

        // Redirect to a new form to add the interests with the draft id and number of interests
        return redirect()->route('add.interests.form', [
            'draft_id' => $draft->id,
            'no_of_interests' => $draft->no_of_interests,
        ]);
    }

    public function showInterestForm($draft_id, $no_of_interests)
    {
        $this->authorize('configure', Draft::findOrFail($draft_id));

        // Pass the draft ID and number of interests to the view
        return view('add_interests', compact('draft_id', 'no_of_interests'));
    }

    public function storeInterests(Request $request, $draft_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'string', 'max:255'],
            'item_images' => ['nullable', 'array'],
            'item_images.*' => ['nullable', 'image', 'max:5120'],
            'return_to' => ['nullable', Rule::in(['edit'])],
        ]);

        $editor->addItems($draft, collect($validated['items'])
            ->map(fn ($name, $index) => ['name' => $name, 'image' => $request->file("item_images.$index")])
            ->values()
            ->all());

        // The edit page adds items one at a time and comes straight back to itself.
        if (($validated['return_to'] ?? null) === 'edit') {
            return redirect()->route('draft.edit', ['draft_id' => $draft->id])->with('status', 'Item added.');
        }

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
        $this->authorize('view', $draft);

        $teams = $draft->teams()->orderBy('selection_no')->get();

        return view('draft_details', compact('draft', 'teams'));
    }

    public function startDraft($draft_id, DraftPickService $picks)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('start', $draft);

        try {
            $picks->start($draft);
        } catch (HttpException $e) {
            // Not ready yet (someone has not joined, or there are no items): tell the host why.
            return redirect()->route('draft.details', ['draft_id' => $draft->id])
                ->withErrors(['start' => $e->getMessage()]);
        }

        // The host watches from the live page, whether or not they are picking too.
        return redirect()->route('show.interests', ['draft_id' => $draft->id]);
    }

    public function showInterests($draft_id, DraftPickService $picks)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('view', $draft);

        try {
            $picks->advanceClock($draft);
            $state = $picks->state($draft);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 422) {
                throw $e;
            }

            // Not ready to show yet (someone has not joined, or there is no order): say why.
            return redirect()->route('draft.details', ['draft_id' => $draft->id])
                ->withErrors(['start' => $e->getMessage()]);
        }

        return view('selection', [
            'draft' => $draft,
            'interests' => $draft->interests,
            'state' => $state,
            // The host may watch without being a participant; only participants can pick.
            'isParticipant' => $this->isParticipant($draft),
        ]);
    }

    public function showState($draft_id, DraftPickService $picks)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('view', $draft);
        $picks->advanceClock($draft);

        return response()->json($picks->state($draft));
    }

    public function selectInterest(Request $request, $draft_id, DraftPickService $picks)
    {
        $validated = $request->validate([
            'interest_id' => 'required|integer',
        ]);

        $state = $picks->pick(Draft::findOrFail($draft_id), $request->user(), $validated['interest_id']);

        return response()->json([
            'success' => true,
            'message' => 'Interest selected successfully.',
            'next_player' => $state['current_player'],
            'state' => $state,
        ]);
    }

    private function isParticipant(Draft $draft): bool
    {
        return $draft->teams()->where('user_id', auth()->id())->exists();
    }
}
