<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PickTurn</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    {{-- Block form on purpose: an inline @php(...) ahead of the @php...@endphp below would swallow the page between them. --}}
    @php
        $isGuest = Auth::user()->isGuest();
    @endphp

    <div class="header">
        <h1 class="user-greeting">{{ $isGuest ? 'Welcome' : 'Welcome back' }}, {{ Auth::user()->username }}</h1>
        {{-- A guest has no password, and a public link only ever makes a new guest: logging out loses their seats. --}}
        <form action="{{ route('logout') }}" method="POST" class="logout-form"
            @if ($isGuest) onsubmit="return confirm('Log out? As a guest you have no password, so you would lose your place in these sessions.')" @endif>
            @csrf
            <button type="submit" class="btn-logout">Log Out</button>
        </form>
    </div>

    <div class="draft-container">
        <div class="draft-box">
            {{-- A guest (public sessions only) cannot host or join private sessions: those need an account. --}}
            @if ($isGuest)
                <a href="{{ route('register') }}">Create an account</a>
            @else
                <a href="{{ route('view.draft') }}">Create a session</a>
            @endif
        </div>
        <div class="draft-box">
            <a href="{{ route('join.draft.form') }}">Join a session</a>
        </div>
    </div>

    <div class="container team-selection-container">
        <h3>Your sessions</h3>

        @if ($drafts->isEmpty())
            <p class="pick-status">You are not part of any session yet. Create one, or join with an invitation link.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Session</th>
                        <th>Your role</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($drafts as $draft)
                        @php
                            $isHost = $draft->user_id === auth()->id();
                            $isParticipant = $draft->teams->isNotEmpty();
                            // Money mode has no items/selections to count against: starting it
                            // is the whole event, so a started one is immediately Complete.
                            $isComplete = $draft->isMoneyMode()
                                ? $draft->turn_started_at !== null
                                : ($draft->interests_count > 0 && ($draft->type === 'bidding'
                                    ? $draft->interests_closed_count >= $draft->interests_count
                                    : $draft->selections_count >= $draft->interests_count));
                        @endphp
                        <tr>
                            <td>{{ $draft->name }} <span class="pick-status">{{ $draft->type === 'bidding' ? 'Bidding' : ($draft->isMoneyMode() ? 'Giveaway (Money Split)' : 'Giveaway') }} &middot; {{ $draft->isPublic() ? 'Public' : 'Private' }}</span></td>
                            <td>{{ $isHost && $isParticipant ? 'Host and participant' : ($isHost ? 'Host' : 'Participant') }}</td>
                            <td>{{ $isComplete ? 'Complete' : ($draft->turn_started_at ? 'In progress' : 'Not started') }}</td>
                            <td>
                                <a href="{{ route('draft.details', ['draft_id' => $draft->id]) }}">Details</a>
                                &middot;
                                @if ($isHost && ! $draft->turn_started_at && $draft->selections_count === 0)
                                    <a href="{{ route('draft.edit', ['draft_id' => $draft->id]) }}">Edit</a>
                                    &middot;
                                @endif
                                <a href="{{ route('show.interests', ['draft_id' => $draft->id]) }}">{{ $isParticipant ? ($draft->isMoneyMode() ? 'Results page' : ($draft->type === 'bidding' ? 'Bidding page' : 'Picking page')) : 'Watch' }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</body>
</html>
