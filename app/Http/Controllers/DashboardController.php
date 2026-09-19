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
            ->withCount(['selections', 'interests'])
            ->with(['teams' => fn ($teams) => $teams->where('user_id', $userId)])
            ->latest()
            ->take(50)
            ->get();

        return view('dashboard', ['drafts' => $drafts]);
    }
}
