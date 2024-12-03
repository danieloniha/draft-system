<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Map</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>

<div class="form-container">
    <h2 class="form-title">Create Map</h2>
    <form method="POST" action="{{ route('create.draft') }}" id="game-config-form" enctype="multipart/form-data">
        @csrf
        <div id="step-1">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" placeholder="Enter name" required>
            </div>

            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" placeholder="Enter title" required>
            </div>

            <div class="form-group">
                <label for="no-interests">No of Interests</label>
                <input type="number" id="no_interests" name="no_interests" placeholder="Enter number of interests" required>
            </div>

            <div class="form-group">
                <label for="no-players">No of Teams</label>
                <input type="number" id="no_teams" name="no_teams" placeholder="Enter number of teams" required>
            </div>

            <div class="form-group">
                <label for="timer">Selection Timer (in seconds)</label>
                <input type="number" id="timer" name="timer" placeholder="Enter selection timer" required>
            </div>

            <div class="form-group">
                <label for="start-date">Start Date</label>
                <input type="datetime-local" id="start_date" name="start_date" placeholder="Enter draft date" required>
            </div>

            <div class="form-group">
                <button type="submit" class="btn" id="next-button">Submit</button>
            </div>
        </div>

    </form>
</div>

</body>
</html>
