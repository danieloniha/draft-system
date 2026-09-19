<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Selection Session</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>

<div class="form-container">
    <h2 class="form-title">Create a Selection Session</h2>
    <form method="POST" action="{{ route('create.draft') }}" id="game-config-form" enctype="multipart/form-data">
        @csrf
        <div id="step-1">
            <div class="form-group">
                <label for="name">Session Name</label>
                <input type="text" id="name" name="name" placeholder="e.g. Saturday Yard Sale" required>
            </div>

            <div class="form-group">
                <label for="title">Description</label>
                <input type="text" id="title" name="title" placeholder="What are participants selecting?" required>
            </div>

            <div class="form-group">
                <label for="no-interests">Number of Items</label>
                <input type="number" id="no_interests" name="no_interests" placeholder="Enter number of items" min="1" required>
            </div>

            <div class="form-group">
                <label for="no-players">Number of Participants</label>
                <input type="number" id="no_teams" name="no_teams" placeholder="Enter number of participants" min="1" required>
            </div>

            <div class="form-group">
                <label for="timer">Turn Timer (seconds)</label>
                <input type="number" id="timer" name="timer" placeholder="Enter turn timer" min="1" required>
            </div>

            <div class="form-group">
                <label for="start-date">Start Date & Time (your local time)</label>
                <input type="datetime-local" id="start_date" name="start_date" required>
                <input type="hidden" id="timezone" name="timezone">
            </div>

            <div class="form-group">
                <button type="submit" class="btn" id="next-button">Continue to Items</button>
            </div>
        </div>

    </form>
</div>

<script>
    // Sent with the form so the start time is read in the creator's own timezone.
    document.getElementById('timezone').value = Intl.DateTimeFormat().resolvedOptions().timeZone;
</script>

</body>
</html>
