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
    <div class="draft-details-container">
        <h2 class="form-title">Session Details</h2>

        <!-- Display draft information -->
        <div class="draft-info">
            <p><strong>Session:</strong> {{ $draft->name }}</p>
            <p><strong>Description:</strong> {{ $draft->title }}</p>
            <p><strong>Participants:</strong> {{ $draft->no_of_teams }}</p>
            <p><strong>Items:</strong> {{ $draft->no_of_interests }}</p>
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
                            <td>{{ $team->user?->username ?? $team->email }}</td>
                            <td>{{ $team->selection_no }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Start draft button -->
        <div class="form-group">
            <form action="{{ route('start.draft', ['draft_id' => $draft->id]) }}" method="POST">
                @csrf
                <button type="submit" class="btn start-btn">Start Selection</button>
            </form>
        </div>
    </div>
</body>
</html>
