<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PickTurn</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    <div class="header">
        <h1 class="user-greeting">Welcome back, {{ Auth::user()->username }}</h1>
        <form action="{{ route('logout') }}" method="POST" class="logout-form">
            @csrf
            <button type="submit" class="btn-logout">Log Out</button>
        </form>
    </div>

    <div class="draft-container">
        <div class="draft-box">
            <a href="{{ route('view.draft') }}">Create a session</a>
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
                            $isComplete = $draft->interests_count > 0 && $draft->selections_count >= $draft->interests_count;
                        @endphp
                        <tr>
                            <td>{{ $draft->name }}</td>
                            <td>{{ $isHost && $isParticipant ? 'Host and participant' : ($isHost ? 'Host' : 'Participant') }}</td>
                            <td>{{ $isComplete ? 'Complete' : ($draft->turn_started_at ? 'In progress' : 'Not started') }}</td>
                            <td>
                                <a href="{{ route('draft.details', ['draft_id' => $draft->id]) }}">Details</a>
                                &middot;
                                @if ($isHost && ! $draft->turn_started_at && $draft->selections_count === 0)
                                    <a href="{{ route('draft.edit', ['draft_id' => $draft->id]) }}">Edit</a>
                                    &middot;
                                @endif
                                <a href="{{ route('show.interests', ['draft_id' => $draft->id]) }}">{{ $isParticipant ? 'Picking page' : 'Watch' }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</body>
</html>
