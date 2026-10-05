<!-- resources/views/create_draft_bidding.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create a Bidding Auction</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>

<div class="form-container">
    <h2 class="form-title">Create a Bidding Auction</h2>
    <p>Items go up for auction one at a time. Participants bid on whichever is open; the highest bid wins it.</p>

    <form method="POST" action="{{ route('create.draft') }}" id="game-config-form" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="type" value="bidding">

        <div class="form-group">
            <label for="name">Session Name</label>
            <input type="text" id="name" name="name" placeholder="e.g. Saturday Auction" required>
        </div>

        <div class="form-group">
            <label for="title">Description (optional)</label>
            <input type="text" id="title" name="title" placeholder="What are participants bidding on?">
        </div>

        <div class="form-group">
            <label for="no-interests">Number of Items</label>
            <input type="number" id="no_interests" name="no_interests" placeholder="Enter number of items" min="1" required>
        </div>

        <div class="form-group">
            <label for="timer">Bid Timer (seconds)</label>
            <input type="number" id="timer" name="timer" placeholder="Enter bid timer" min="1" required>
            <p class="hint">How long each item stays open for bids before it closes.</p>
        </div>

        <div class="form-group">
            <label for="start-date">Start Date & Time (your local time)</label>
            <input type="datetime-local" id="start_date" name="start_date" required>
            <input type="hidden" id="timezone" name="timezone">
        </div>

        <div class="form-group">
            <label for="visibility">Who Can Join</label>
            <select id="visibility" name="visibility" required>
                <option value="private" selected>Private — you invite people by email, and they need an account</option>
                <option value="public">Public — anyone with the link can join</option>
            </select>
        </div>

        <div class="form-group">
            <label for="order_mode">Item Order</label>
            <select id="order_mode" name="order_mode" required>
                <option value="host_decided" selected>You decide the order</option>
                <option value="fcfs">First come, first served (the order items are added)</option>
                <option value="random">Random</option>
            </select>
            <p class="hint">Which item goes up for auction first, second, and so on.</p>
        </div>

        <div class="form-group">
            <label for="participant_limit" id="participant_limit_label">Number of Participants</label>
            <input type="number" id="participant_limit" name="participant_limit" min="1" max="100" placeholder="e.g. 4" required>
            <p class="hint" id="participant_limit_hint">How many people will take part. You'll invite each of them by email on the next step.</p>
        </div>
        {{-- Private: everyone entered above gets invited by email next, so the invite step needs
             exactly that many blank fields. Public: nobody is pre-invited by email at creation —
             people join through the link instead, up to the same number as the cap above. --}}
        <input type="hidden" id="no_teams" name="no_teams" value="0">

        <div class="form-group">
            <button type="submit" class="btn" id="next-button">Continue to Items</button>
        </div>
    </form>
</div>

<script>
    // Sent with the form so the start time is read in the creator's own timezone.
    document.getElementById('timezone').value = Intl.DateTimeFormat().resolvedOptions().timeZone;

    // One field means two things depending on visibility: for Private it's literally how many
    // people to invite by email next; for Public it's just the self-join cap on the link, and
    // nobody is pre-invited by email at creation (the host still can, later, from the edit page).
    const visibilitySelect = document.getElementById('visibility');
    const participantLimitInput = document.getElementById('participant_limit');
    const participantLimitLabel = document.getElementById('participant_limit_label');
    const participantLimitHint = document.getElementById('participant_limit_hint');
    const noTeamsHidden = document.getElementById('no_teams');

    function syncNoTeams() {
        noTeamsHidden.value = visibilitySelect.value === 'public' ? '0' : (participantLimitInput.value || '0');
    }

    function applyVisibility() {
        const isPublic = visibilitySelect.value === 'public';

        participantLimitLabel.textContent = isPublic ? 'Participant Limit' : 'Number of Participants';
        participantLimitInput.placeholder = isPublic ? 'e.g. 100' : 'e.g. 4';
        participantLimitHint.textContent = isPublic
            ? 'The public link closes once this many people have joined. You can still invite specific people by email from the edit page.'
            : "How many people will take part. You'll invite each of them by email on the next step.";

        syncNoTeams();
    }

    visibilitySelect.addEventListener('change', applyVisibility);
    participantLimitInput.addEventListener('input', syncNoTeams);
    applyVisibility();
</script>

</body>
</html>
