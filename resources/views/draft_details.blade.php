<!-- resources/views/draft_details.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Details</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    @php
        $isHost = auth()->user()->can('manage', $draft);
        $isParticipant = $teams->contains('user_id', auth()->id());
        $hasStarted = $draft->turn_started_at !== null;
    @endphp

    <div class="draft-details-container">
        <h2 class="form-title">Session Details</h2>

        {{-- Why the draft could not be started, or why the picking page could not be shown yet. --}}
        @if ($errors->has('start'))
            <p class="notice notice-error" role="alert">{{ $errors->first('start') }}</p>
        @endif

        <!-- Display draft information -->
        <div class="draft-info">
            <p><strong>Session:</strong> {{ $draft->name }}</p>
            <p><strong>Description:</strong> {{ $draft->title }}</p>
            <p><strong>Participants:</strong> {{ $teams->count() }}</p>
            <p><strong>Items:</strong> {{ $draft->interests()->count() }}</p>
            <p><strong>Scheduled start:</strong> <time datetime="{{ $draft->start_date->toIso8601String() }}">{{ $draft->start_date->format('Y-m-d H:i') }} UTC</time></p>
            <p><strong>Turn timer:</strong> {{ $draft->selection_time_limit }} seconds</p>
        </div>

        <!-- Display teams and their selection numbers -->
        <div class="team-selection-container">
            <h3>Participants and Selection Order</h3>
            <table>
                <thead>
                    <tr>
                        <th>Participant</th>
                        <th>Selection Number</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($teams as $team)
                        <tr>
                            {{-- Only the host sees the email of someone who has not joined yet. --}}
                            <td>{{ $team->user?->username ?? ($isHost ? $team->email : 'Invited (not joined yet)') }}</td>
                            <td>{{ $team->selection_no }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Start the draft (host) / go to the picking page (participants) -->
        <div class="form-group">
            @if ($hasStarted)
                <p>The draft has started.</p>
            @elseif ($isHost)
                <p>You are the host. Nobody can pick until you start the draft.</p>
            @else
                <p>Waiting for the host to start the draft.</p>
            @endif

            @if ($isHost && ! $hasStarted)
                <form action="{{ route('start.draft', ['draft_id' => $draft->id]) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn start-btn">Start Draft</button>
                </form>
                <a class="btn start-btn" href="{{ route('draft.edit', ['draft_id' => $draft->id]) }}">Edit session</a>
            @endif

            @if ($isParticipant || $isHost)
                <a class="btn start-btn" href="{{ route('show.interests', ['draft_id' => $draft->id]) }}">{{ $isParticipant ? 'Go to picking page' : 'Watch the draft' }}</a>
            @endif
        </div>
    </div>

    <script>
        // Show times in the viewer's own timezone.
        document.querySelectorAll('time[datetime]').forEach((el) => {
            el.textContent = new Date(el.getAttribute('datetime')).toLocaleString();
        });
    </script>
</body>
</html>
