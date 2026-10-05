<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Models\Team;
use App\Models\User;
use App\Services\Contracts\DraftFlowService;
use App\Services\DraftBiddingService;
use App\Services\DraftEditor;
use App\Services\DraftJoinService;
use App\Services\DraftPayoutService;
use App\Services\DraftPickService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DraftController extends Controller
{
    public function __construct(
        private DraftPickService $picks,
        private DraftBiddingService $bidding,
        private DraftPayoutService $payout,
        private DraftJoinService $join,
    ) {
    }

    public function chooseType()
    {
        return view('choose_draft_type');
    }

    public function giveawayForm()
    {
        return view('create_draft_giveaway');
    }

    public function biddingForm()
    {
        return view('create_draft_bidding');
    }

    public function createDraft(Request $request)
    {
        //dd($request->all());

        $validated = $request->validate([
            'name' => 'required|string',
            'title' => 'nullable|string|max:255',
            'no_interests' => 'required|integer|min:1',
            // 0 for Public: the create form sends no pre-invites for Public drafts, since people
            // join through the link instead (see DraftController::afterItemsStep).
            'no_teams' => 'required|integer|min:0',
            'timer' => 'required|integer|min:1',
            'start_date' => 'required|date',
            'timezone' => 'nullable|timezone',
            'type' => ['required', Rule::in(['giveaway', 'bidding'])],
            'giveaway_mode' => ['nullable', Rule::in(['items', 'money'])],
            'visibility' => ['required', Rule::in(['private', 'public'])],
            'order_mode' => ['required', Rule::in(['host_decided', 'fcfs', 'random'])],
            'participant_limit' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        // The form sends a bare local time. Read it in the creator's timezone and
        // store it in the app's, or the draft would start at the wrong moment.
        $startsAt = Draft::parseStartDate($validated['start_date'], $validated['timezone'] ?? null);

        // Only meaningful for type=giveaway; the bidding form never sends it.
        $giveawayMode = $validated['type'] === 'giveaway' ? ($validated['giveaway_mode'] ?? 'items') : 'items';

        // Optional: an empty string (a blank but submitted field) means the same as leaving it out.
        $title = ($validated['title'] ?? null) ?: null;

        // Create draft
        $draft = Draft::create([
            'user_id' => auth()->id(),
            'name' => $validated['name'],
            'title' => $title,
            'no_of_interests' => $validated['no_interests'],
            'no_of_teams' => $validated['no_teams'],
            'selection_time_limit' => $validated['timer'],
            'start_date' => $startsAt,
            'type' => $validated['type'],
            'giveaway_mode' => $giveawayMode,
            'visibility' => $validated['visibility'],
            'order_mode' => $validated['order_mode'],
            'participant_limit' => $validated['participant_limit'],
        ]);
        $draft->ensurePublicToken();

        // Money mode skips items entirely — the host sets up a payout table instead.
        if ($draft->isMoneyMode()) {
            return redirect()->route('add.payout-tiers.form', ['draft_id' => $draft->id]);
        }

        // Redirect to a new form to add the interests with the draft id and number of interests
        return redirect()->route('add.interests.form', [
            'draft_id' => $draft->id,
            'no_of_interests' => $draft->no_of_interests,
        ]);
    }

    public function showPayoutTiersForm($draft_id)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        return view('add_payout_tiers', compact('draft'));
    }

    public function storePayoutTiers(Request $request, $draft_id, DraftEditor $editor)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('configure', $draft);

        $validated = $request->validate([
            // Required the first time (the wizard step); left out when the edit page's "add a
            // tier" mini-form tops up an existing payout table without touching the budget.
            'payout_budget' => [Rule::requiredIf(fn () => $request->input('return_to') !== 'edit'), 'nullable', 'integer', 'min:1'],
            'rank_from' => ['required', 'array', 'min:1'],
            'rank_from.*' => ['required', 'integer', 'min:1'],
            'rank_to' => ['required', 'array', 'min:1'],
            'rank_to.*' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'array', 'min:1'],
            'amount.*' => ['required', 'integer', 'min:1'],
            'return_to' => ['nullable', Rule::in(['edit'])],
        ]);

        $tiers = collect($validated['rank_from'])
            ->map(fn ($rankFrom, $index) => [
                'rank_from' => (int) $rankFrom,
                'rank_to' => (int) $validated['rank_to'][$index],
                'amount' => (int) $validated['amount'][$index],
            ])
            ->values()
            ->all();

        $budget = isset($validated['payout_budget']) ? (int) $validated['payout_budget'] : null;
        $editor->addPayoutTiers($draft, $tiers, $budget);

        // The edit page adds tiers one batch at a time and comes straight back to itself.
        if (($validated['return_to'] ?? null) === 'edit') {
            return redirect()->route('draft.edit', ['draft_id' => $draft->id])->with('status', 'Payout tiers added.');
        }

        return $this->afterItemsStep($draft);
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

        return $this->afterItemsStep($draft);
    }

    /**
     * Where the wizard goes once items/payout tiers are set up. Private drafts still invite
     * people by email next. Public drafts skip that entirely — nobody is pre-invited by email
     * at creation, since people join through the link instead; the host can still invite
     * specific people later from the edit page, if they want to.
     */
    private function afterItemsStep(Draft $draft)
    {
        if ($draft->isPublic()) {
            return redirect()->route('draft.created', ['draft_id' => $draft->id]);
        }

        return redirect()->route('add.teams.form', [
            'draft_id' => $draft->id,
            'no_of_teams' => $draft->no_of_teams,
        ]);
    }

    public function showJoinDraftForm(Request $request)
    {
        $token = $request->query('token');
        // Say which session the link is for, so someone opening it cold knows what they are joining.
        $invite = $token ? Team::with('draft')->where('token', $token)->first() : null;

        return view('join_draft', ['token' => $token, 'draftName' => $invite?->draft?->name]);
    }

    /**
     * Accept a private invitation. Always an account here (the route is behind `auth` and
     * `account:private`), and it has to be the one the invitation was sent to: its email must
     * match the invited one.
     */
    public function joinDraft(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'token' => 'required|string|exists:teams,token',
        ]);

        $team = Team::where('token', $validated['token'])->firstOrFail();
        $details = redirect()->route('draft.details', ['draft_id' => $team->draft_id]);

        if ($team->user_id !== null) {
            // Already theirs is harmless (a duplicate submit); anyone else's is taken.
            return (int) $team->user_id === (int) $user->id
                ? $details
                : back()->withErrors(['token' => 'This invitation has already been used.']);
        }

        if (strcasecmp((string) $team->email, (string) $user->email) !== 0) {
            return back()->withErrors(['token' => 'This invitation belongs to a different email address.']);
        }

        // False if someone else took the seat between the checks above and now.
        if (! $this->join->joinPrivate($team, $user)) {
            return back()->withErrors(['token' => 'This invitation has already been used.']);
        }

        return $details;
    }

    public function showPublicJoinForm($public_token)
    {
        $draft = Draft::with('creator')->where('public_token', $public_token)->firstOrFail();

        return view('public_join', [
            'draft' => $draft,
            'isParticipant' => $this->isParticipant($draft),
            'count' => $draft->teams()->count(),
        ]);
    }

    public function joinPublicDraft(Request $request, $public_token)
    {
        $draft = Draft::where('public_token', $public_token)->firstOrFail();
        $user = $request->user();

        $validated = $request->validate([
            // Only asked of someone who is not logged in: the name their guest account gets.
            'username' => [Rule::requiredIf($user === null), 'nullable', 'string', 'max:50'],
        ]);

        try {
            if ($user === null) {
                // A draft that cannot be joined (closed, started, full) leaves no guest account behind.
                $user = DB::transaction(function () use ($validated, $draft) {
                    $guest = User::createGuest($validated['username']);
                    $this->join->joinPublic($draft, $guest);

                    return $guest;
                });
                $this->signIn($request, $user);
            } else {
                $this->join->joinPublic($draft, $user);
            }
        } catch (HttpException $e) {
            // Not open, already started, or full: tell them why, on the same page.
            return redirect()->route('public.join.form', ['public_token' => $public_token])
                ->withErrors(['join' => $e->getMessage()]);
        }

        return redirect()->route('draft.details', ['draft_id' => $draft->id]);
    }

    public function showDraftDetails($draft_id)
    {
        $draft = Draft::with('teams.user')->findOrFail($draft_id);
        $this->authorize('view', $draft);

        $teams = $draft->teams()->orderBy('selection_no')->get();

        return view('draft_details', compact('draft', 'teams'));
    }

    public function startDraft($draft_id)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('start', $draft);

        try {
            $this->flowService($draft)->start($draft);
        } catch (HttpException $e) {
            // Not ready yet (someone has not joined, or there are no items): tell the host why.
            return redirect()->route('draft.details', ['draft_id' => $draft->id])
                ->withErrors(['start' => $e->getMessage()]);
        }

        // The host watches from the live page, whether or not they are picking/bidding too.
        return redirect()->route('show.interests', ['draft_id' => $draft->id]);
    }

    public function showInterests($draft_id)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('view', $draft);
        $service = $this->flowService($draft);

        try {
            $service->advanceClock($draft);
            $state = $service->state($draft);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 422) {
                throw $e;
            }

            // Not ready to show yet (someone has not joined, or similar): say why.
            return redirect()->route('draft.details', ['draft_id' => $draft->id])
                ->withErrors(['start' => $e->getMessage()]);
        }

        $view = match (true) {
            $draft->isMoneyMode() => 'payout_result',
            $draft->isBidding() => 'bidding',
            default => 'selection',
        };

        return view($view, [
            'draft' => $draft,
            'interests' => $draft->interests,
            'state' => $state,
            // The host may watch without being a participant; only participants can pick/bid.
            'isParticipant' => $this->isParticipant($draft),
        ]);
    }

    public function showState($draft_id)
    {
        $draft = Draft::findOrFail($draft_id);
        $this->authorize('view', $draft);
        $service = $this->flowService($draft);
        $service->advanceClock($draft);

        return response()->json($service->state($draft));
    }

    public function selectInterest(Request $request, $draft_id)
    {
        $validated = $request->validate([
            'interest_id' => 'required|integer',
        ]);

        $state = $this->picks->pick(Draft::findOrFail($draft_id), $request->user(), $validated['interest_id']);

        return response()->json([
            'success' => true,
            'message' => 'Interest selected successfully.',
            'next_player' => $state['current_player'],
            'state' => $state,
        ]);
    }

    public function placeBid(Request $request, $draft_id)
    {
        $validated = $request->validate([
            'interest_id' => 'required|integer',
            'amount' => 'required|integer|min:1',
        ]);

        $state = $this->bidding->placeBid(
            Draft::findOrFail($draft_id),
            $request->user(),
            $validated['interest_id'],
            $validated['amount']
        );

        return response()->json([
            'success' => true,
            'message' => 'Bid placed successfully.',
            'state' => $state,
        ]);
    }

    private function isParticipant(Draft $draft): bool
    {
        // Not logged in must not fall through to `user_id = null`, which would match every
        // invited-but-not-yet-joined seat and call a stranger a participant.
        return auth()->check() && $draft->teams()->where('user_id', auth()->id())->exists();
    }

    /**
     * Log a guest in. Remembered, so they stay in across browser restarts: for a guest the
     * login is the whole account.
     */
    private function signIn(Request $request, User $user): void
    {
        Auth::login($user, remember: true);
        $request->session()->regenerate();
    }

    private function flowService(Draft $draft): DraftFlowService
    {
        return match (true) {
            $draft->isMoneyMode() => $this->payout,
            $draft->isBidding() => $this->bidding,
            default => $this->picks,
        };
    }
}
