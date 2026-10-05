<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * The home page: the sessions the user hosts or takes part in, newest first.
     */
    public function __invoke(Request $request)
    {
        $userId = $request->user()->id;

        $drafts = Draft::query()
            ->where('user_id', $userId)
            ->orWhereHas('teams', fn ($teams) => $teams->where('user_id', $userId))
            ->withCount([
                'selections',
                'interests',
                // Bidding never creates selections, so its own completion signal is
                // "every item has closed" rather than "every item has been picked".
                'interests as interests_closed_count' => fn ($items) => $items->whereNotNull('closed_at'),
            ])
            ->with(['teams' => fn ($teams) => $teams->where('user_id', $userId)])
            ->latest()
            ->take(50)
            ->get();

        return view('dashboard', ['drafts' => $drafts]);
    }
}
